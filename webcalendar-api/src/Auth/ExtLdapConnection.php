<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * LdapConnection backed by ext-ldap.
 *
 * Nothing here decides anything: it is the thin edge that the rest of the LDAP
 * code is tested without.
 */
final class ExtLdapConnection implements LdapConnection
{
    /**
     * @param \LDAP\Connection $connection kept as mixed because the class only
     *   exists when ext-ldap is loaded, and that is optional here
     */
    public function __construct(private readonly mixed $connection) {}

    #[\Override]
    public function bind(string $dn, #[\SensitiveParameter] string $password): bool
    {
        // Silenced: a rejected bind is an expected outcome, not a warning.
        return @ldap_bind($this->connection, $dn === '' ? null : $dn, $password === '' ? null : $password);
    }

    #[\Override]
    public function search(string $baseDn, string $filter, array $attributes): ?array
    {
        return $this->entries(@ldap_search($this->connection, $baseDn, $filter, $attributes));
    }

    #[\Override]
    public function read(string $dn, string $filter, array $attributes): ?array
    {
        return $this->entries(@ldap_read($this->connection, $dn, $filter, $attributes));
    }

    #[\Override]
    public function close(): void
    {
        ldap_unbind($this->connection);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function entries(mixed $result): ?array
    {
        // false on failure, and an array when searching several base DNs at
        // once -- which this code never does, so treat that as a failure too
        // rather than guessing which result was meant. instanceof covers both
        // and is what narrows the value for the call below.
        if (!$result instanceof \LDAP\Result) {
            return null;
        }

        $entries = ldap_get_entries($this->connection, $result);

        return \is_array($entries) ? $entries : null;
    }
}
