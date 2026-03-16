<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Manages PDO connections to tenant databases.
 *
 * Creates connections dynamically from tenant credentials, caches them
 * within the request lifecycle, and handles credential encryption.
 */
final class TenantDatabaseManager
{
    private const CIPHER = 'aes-256-gcm';

    /** @var array<string, \PDO> */
    private array $connections = [];

    public function __construct(
        private readonly string $appSecret,
    ) {
    }

    /**
     * Gets a PDO connection for a tenant, creating and caching it if needed.
     *
     * @throws \RuntimeException If the tenant database is unreachable
     */
    public function getConnection(Tenant $tenant): \PDO
    {
        $slug = $tenant->slug();

        if (isset($this->connections[$slug])) {
            return $this->connections[$slug];
        }

        $pdo = $this->createConnection($tenant);
        $this->connections[$slug] = $pdo;

        return $pdo;
    }

    /**
     * Removes a cached connection for a tenant slug.
     */
    public function clearConnection(string $slug): void
    {
        unset($this->connections[$slug]);
    }

    /**
     * Encrypts a database password for storage in the tenant registry.
     */
    public function encryptPassword(string $plaintext): string
    {
        $key = $this->deriveKey();
        /** @var int<1, max> $ivLength */
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';
        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // Store as base64(iv + tag + ciphertext)
        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Decrypts a database password from the tenant registry.
     *
     * @throws \RuntimeException If decryption fails (wrong key or corrupted data)
     */
    public function decryptPassword(string $encrypted): string
    {
        $key = $this->deriveKey();
        $raw = base64_decode($encrypted, true);

        if ($raw === false) {
            throw new \RuntimeException('Failed to decode encrypted password');
        }

        /** @var int $ivLength */
        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        $tagLength = 16; // GCM tag is always 16 bytes
        $minLength = $ivLength + $tagLength;

        if (\strlen($raw) < $minLength) {
            throw new \RuntimeException('Encrypted data too short');
        }

        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, $tagLength);
        $ciphertext = substr($raw, $ivLength + $tagLength);

        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed — wrong key or corrupted data');
        }

        return $decrypted;
    }

    private function createConnection(Tenant $tenant): \PDO
    {
        $dbHost = $tenant->dbHost();
        $dbName = $tenant->dbName();

        // SQLite support (for testing)
        if ($dbHost === '' && ($dbName === ':memory:' || str_ends_with($dbName, '.sqlite'))) {
            try {
                $pdo = new \PDO("sqlite:{$dbName}");
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                return $pdo;
            } catch (\PDOException $e) {
                throw new \RuntimeException("Unable to connect to tenant '{$tenant->slug()}' database: {$e->getMessage()}", 0, $e);
            }
        }

        // Decrypt password if it's encrypted
        $password = $tenant->dbPassword();
        if ($password !== '') {
            try {
                $password = $this->decryptPassword($password);
            } catch (\RuntimeException) {
                // If decryption fails, assume plaintext (for development/migration)
            }
        }

        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

        try {
            $pdo = new \PDO($dsn, $tenant->dbUser(), $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            return $pdo;
        } catch (\PDOException $e) {
            throw new \RuntimeException("Unable to connect to tenant '{$tenant->slug()}' database: {$e->getMessage()}", 0, $e);
        }
    }

    private function deriveKey(): string
    {
        // Derive a 32-byte key from the app secret using SHA-256
        return hash('sha256', $this->appSecret, true);
    }
}
