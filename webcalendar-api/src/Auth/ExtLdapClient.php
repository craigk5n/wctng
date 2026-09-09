<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * LdapClient backed by ext-ldap.
 */
final class ExtLdapClient implements LdapClient
{
    private const PROTOCOL_VERSION = 3;
    private const NETWORK_TIMEOUT_SECONDS = 5;

    #[\Override]
    public function isSupported(): bool
    {
        return \function_exists('ldap_connect');
    }

    #[\Override]
    public function connect(string $uri): ?LdapConnection
    {
        $connection = @ldap_connect($uri);

        if ($connection === false) {
            return null;
        }

        ldap_set_option($connection, \LDAP_OPT_PROTOCOL_VERSION, self::PROTOCOL_VERSION);
        // Referrals off: chasing one would take the bind somewhere the
        // configured host never named.
        ldap_set_option($connection, \LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, \LDAP_OPT_NETWORK_TIMEOUT, self::NETWORK_TIMEOUT_SECONDS);

        return new ExtLdapConnection($connection);
    }

    #[\Override]
    public function escapeFilterValue(string $value): string
    {
        return ldap_escape($value, '', \LDAP_ESCAPE_FILTER);
    }
}
