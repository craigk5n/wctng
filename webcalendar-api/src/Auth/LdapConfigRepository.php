<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Repository for LDAP configuration stored in the database.
 * Single-row table — one config per database (tenant or global).
 */
final readonly class LdapConfigRepository
{
    public const SCHEMA_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS ldap_config (
            id INTEGER PRIMARY KEY DEFAULT 1,
            host VARCHAR(255) NOT NULL DEFAULT '',
            port INTEGER NOT NULL DEFAULT 389,
            base_dn VARCHAR(500) NOT NULL DEFAULT '',
            bind_dn VARCHAR(500) NOT NULL DEFAULT '',
            bind_password TEXT NOT NULL DEFAULT '',
            user_filter VARCHAR(255) NOT NULL DEFAULT '(uid=%s)',
            use_tls INTEGER NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 0
        )
    SQL;

    public function __construct(
        private \PDO $pdo,
    ) {
    }

    public function get(): LdapConfig
    {
        $this->ensureTable();
        $stmt = $this->pdo->query('SELECT * FROM ldap_config WHERE id = 1');
        if ($stmt === false) {
            return new LdapConfig();
        }

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            return new LdapConfig();
        }

        /** @var array<string, mixed> $row */
        return new LdapConfig(
            host: \is_string($row['host'] ?? null) ? $row['host'] : '',
            port: \is_numeric($row['port'] ?? null) ? (int) $row['port'] : 389,
            baseDn: \is_string($row['base_dn'] ?? null) ? $row['base_dn'] : '',
            bindDn: \is_string($row['bind_dn'] ?? null) ? $row['bind_dn'] : '',
            bindPassword: \is_string($row['bind_password'] ?? null) ? $row['bind_password'] : '',
            userFilter: \is_string($row['user_filter'] ?? null) ? $row['user_filter'] : '(uid=%s)',
            useTls: (\is_numeric($row['use_tls'] ?? null) ? (int) $row['use_tls'] : 0) === 1,
            enabled: (\is_numeric($row['enabled'] ?? null) ? (int) $row['enabled'] : 0) === 1,
        );
    }

    public function save(LdapConfig $config): void
    {
        $this->ensureTable();

        $stmt = $this->pdo->query('SELECT 1 FROM ldap_config WHERE id = 1');
        $exists = $stmt !== false && $stmt->fetch() !== false;

        if ($exists) {
            $this->pdo->prepare(
                'UPDATE ldap_config SET host=:host, port=:port, base_dn=:base_dn, bind_dn=:bind_dn,
                 bind_password=:bind_password, user_filter=:user_filter, use_tls=:use_tls, enabled=:enabled
                 WHERE id = 1',
            )->execute([
                'host' => $config->host(),
                'port' => $config->port(),
                'base_dn' => $config->baseDn(),
                'bind_dn' => $config->bindDn(),
                'bind_password' => $config->bindPassword(),
                'user_filter' => $config->userFilter(),
                'use_tls' => $config->useTls() ? 1 : 0,
                'enabled' => $config->isEnabled() ? 1 : 0,
            ]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO ldap_config (id, host, port, base_dn, bind_dn, bind_password, user_filter, use_tls, enabled)
                 VALUES (1, :host, :port, :base_dn, :bind_dn, :bind_password, :user_filter, :use_tls, :enabled)',
            )->execute([
                'host' => $config->host(),
                'port' => $config->port(),
                'base_dn' => $config->baseDn(),
                'bind_dn' => $config->bindDn(),
                'bind_password' => $config->bindPassword(),
                'user_filter' => $config->userFilter(),
                'use_tls' => $config->useTls() ? 1 : 0,
                'enabled' => $config->isEnabled() ? 1 : 0,
            ]);
        }
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(self::SCHEMA_SQL);
    }
}
