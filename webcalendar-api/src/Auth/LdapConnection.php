<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * One LDAP connection's worth of operations.
 *
 * search() and read() return decoded entries rather than a result handle: every
 * caller followed ldap_search()/ldap_read() immediately with ldap_get_entries(),
 * so the pair is the actual unit of work and splitting them only leaked a
 * handle type that cannot be named without the extension loaded.
 */
interface LdapConnection
{
    /**
     * @param string $dn       empty for an anonymous bind
     * @param string $password empty for an anonymous bind
     */
    public function bind(string $dn, #[\SensitiveParameter] string $password): bool;

    /**
     * @param list<string> $attributes
     *
     * @return array<array-key, mixed>|null null when the search failed
     */
    public function search(string $baseDn, string $filter, array $attributes): ?array;

    /**
     * @param list<string> $attributes
     *
     * @return array<array-key, mixed>|null null when the read failed
     */
    public function read(string $dn, string $filter, array $attributes): ?array;

    public function close(): void;
}
