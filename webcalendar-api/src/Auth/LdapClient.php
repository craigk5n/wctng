<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The ldap_* extension functions, behind an interface.
 *
 * The seam is deliberately at the raw-call level rather than around whole
 * operations like "find this user's DN". Wrapping the operations would move
 * the URI construction, the anonymous-versus-service-bind choice and the entry
 * decoding into the adapter, which is exactly the code that needs testing;
 * putting it here leaves that logic in LdapAuthenticator where a fake can
 * drive it, and takes only the I/O out of reach.
 */
interface LdapClient
{
    /**
     * Whether ext-ldap is present. The extension is optional (composer lists
     * it under suggest), so every entry point checks this first.
     */
    public function isSupported(): bool;

    /**
     * @param string $uri ldap:// or ldaps:// with host and port
     *
     * @return LdapConnection|null null when the URI cannot be used at all;
     *   note that ext-ldap connects lazily, so a reachable-looking result here
     *   says nothing about whether the server answers
     */
    public function connect(string $uri): ?LdapConnection;

    /**
     * Escapes a value for interpolation into a search filter.
     */
    public function escapeFilterValue(string $value): string;
}
