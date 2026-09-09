<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Repository for OAuth2/OIDC provider configurations.
 */
final readonly class OAuthProviderRepository
{
    public const SCHEMA_SQL = <<<'SQL'
            CREATE TABLE IF NOT EXISTS oauth_providers (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'oauth2',
                client_id VARCHAR(255) NOT NULL,
                client_secret TEXT NOT NULL,
                auth_url VARCHAR(500) NOT NULL DEFAULT '',
                token_url VARCHAR(500) NOT NULL DEFAULT '',
                userinfo_url VARCHAR(500) NOT NULL DEFAULT '',
                scopes VARCHAR(500) NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 1
            )
        SQL;

    public const SCHEMA_SQL_SQLITE = <<<'SQL'
            CREATE TABLE IF NOT EXISTS oauth_providers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'oauth2',
                client_id VARCHAR(255) NOT NULL,
                client_secret TEXT NOT NULL,
                auth_url VARCHAR(500) NOT NULL DEFAULT '',
                token_url VARCHAR(500) NOT NULL DEFAULT '',
                userinfo_url VARCHAR(500) NOT NULL DEFAULT '',
                scopes VARCHAR(500) NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 1
            )
        SQL;

    public function __construct(
        private \PDO $pdo,
    ) {}

    public function ensureTable(): void
    {
        // AUTOINCREMENT is SQLite-only; MySQL spells it AUTO_INCREMENT and
        // errors out on the other form, which left oauth_providers uncreated
        // and every write to this table failing with a 500 on MySQL.
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $this->pdo->exec($driver === 'sqlite' ? self::SCHEMA_SQL_SQLITE : self::SCHEMA_SQL);
    }

    /**
     * @return OAuthProvider[]
     */
    public function findAll(): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->query('SELECT * FROM oauth_providers ORDER BY name');
        if ($stmt === false) {
            return [];
        }

        $providers = [];
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $providers[] = $this->mapRow($row);
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return $providers;
    }

    public function findById(int $id): ?OAuthProvider
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT * FROM oauth_providers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $this->mapRow($row);
    }

    public function save(OAuthProvider $provider): int
    {
        $this->ensureTable();

        if ($provider->id() > 0) {
            $this->pdo->prepare(
                'UPDATE oauth_providers SET name=:name, type=:type, client_id=:client_id, client_secret=:client_secret,
                 auth_url=:auth_url, token_url=:token_url, userinfo_url=:userinfo_url, scopes=:scopes, enabled=:enabled
                 WHERE id=:id',
            )->execute([
                'id' => $provider->id(),
                'name' => $provider->name(),
                'type' => $provider->type(),
                'client_id' => $provider->clientId(),
                'client_secret' => $provider->clientSecret(),
                'auth_url' => $provider->authUrl(),
                'token_url' => $provider->tokenUrl(),
                'userinfo_url' => $provider->userinfoUrl(),
                'scopes' => $provider->scopes(),
                'enabled' => $provider->isEnabled() ? 1 : 0,
            ]);

            return $provider->id();
        }

        $this->pdo->prepare(
            'INSERT INTO oauth_providers (name, type, client_id, client_secret, auth_url, token_url, userinfo_url, scopes, enabled)
             VALUES (:name, :type, :client_id, :client_secret, :auth_url, :token_url, :userinfo_url, :scopes, :enabled)',
        )->execute([
            'name' => $provider->name(),
            'type' => $provider->type(),
            'client_id' => $provider->clientId(),
            'client_secret' => $provider->clientSecret(),
            'auth_url' => $provider->authUrl(),
            'token_url' => $provider->tokenUrl(),
            'userinfo_url' => $provider->userinfoUrl(),
            'scopes' => $provider->scopes(),
            'enabled' => $provider->isEnabled() ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM oauth_providers WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): OAuthProvider
    {
        return new OAuthProvider(
            id: \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            name: \is_string($row['name'] ?? null) ? $row['name'] : '',
            type: \is_string($row['type'] ?? null) ? $row['type'] : 'oauth2',
            clientId: \is_string($row['client_id'] ?? null) ? $row['client_id'] : '',
            clientSecret: \is_string($row['client_secret'] ?? null) ? $row['client_secret'] : '',
            authUrl: \is_string($row['auth_url'] ?? null) ? $row['auth_url'] : '',
            tokenUrl: \is_string($row['token_url'] ?? null) ? $row['token_url'] : '',
            userinfoUrl: \is_string($row['userinfo_url'] ?? null) ? $row['userinfo_url'] : '',
            scopes: \is_string($row['scopes'] ?? null) ? $row['scopes'] : '',
            enabled: (\is_numeric($row['enabled'] ?? null) ? (int) $row['enabled'] : 1) === 1,
        );
    }
}
