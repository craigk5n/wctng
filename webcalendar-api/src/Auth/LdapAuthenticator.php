<?php

declare(strict_types=1);

namespace App\Auth;

use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

/**
 * Authenticates users against an LDAP directory.
 *
 * On successful auth, auto-provisions or syncs the webcalendar user.
 */
final class LdapAuthenticator
{
    public function __construct(
        private readonly LdapConfigRepository $configRepo,
        private readonly UserService $userService,
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * Attempts LDAP authentication. Returns the webcalendar User on success, null on failure.
     */
    public function authenticate(string $username, #[\SensitiveParameter] string $password): ?User
    {
        $config = $this->configRepo->get();
        if (!$config->isEnabled() || $config->host() === '') {
            return null;
        }

        if (!\function_exists('ldap_connect')) {
            return null;
        }

        // Search for the user DN
        $userDn = $this->findUserDn($config, $username);
        if ($userDn === null) {
            return null;
        }

        // Bind as the user to verify password
        if (!$this->bindAsUser($config, $userDn, $password)) {
            return null;
        }

        // Fetch user attributes
        $attrs = $this->fetchUserAttributes($config, $userDn);

        // Auto-provision or sync the webcalendar user
        return $this->provisionOrSync($username, $attrs);
    }

    /**
     * Checks if LDAP authentication is available (enabled and extension loaded).
     */
    public function isAvailable(): bool
    {
        $config = $this->configRepo->get();

        return $config->isEnabled() && $config->host() !== '' && \function_exists('ldap_connect');
    }

    private function findUserDn(LdapConfig $config, string $username): ?string
    {
        $conn = $this->connect($config);
        if ($conn === null) {
            return null;
        }

        // Bind with service account
        if ($config->bindDn() !== '') {
            if (!@ldap_bind($conn, $config->bindDn(), $config->bindPassword())) {
                ldap_unbind($conn);

                return null;
            }
        }

        // Search for user
        $filter = sprintf($config->userFilter(), ldap_escape($username, '', LDAP_ESCAPE_FILTER));
        $search = @ldap_search($conn, $config->baseDn(), $filter, ['dn']);

        if ($search === false || \is_array($search)) {
            ldap_unbind($conn);

            return null;
        }

        $entries = ldap_get_entries($conn, $search);
        ldap_unbind($conn);

        if ($entries === false || $entries['count'] !== 1) {
            return null;
        }

        if (!isset($entries[0]) || !\is_array($entries[0])) {
            return null;
        }

        $entry = $entries[0];

        return isset($entry['dn']) && \is_string($entry['dn']) ? $entry['dn'] : null;
    }

    private function bindAsUser(LdapConfig $config, string $userDn, #[\SensitiveParameter] string $password): bool
    {
        $conn = $this->connect($config);
        if ($conn === null) {
            return false;
        }

        $result = @ldap_bind($conn, $userDn, $password);
        ldap_unbind($conn);

        return $result;
    }

    /**
     * @return array{name: string, email: string, firstname: string, lastname: string}
     */
    private function fetchUserAttributes(LdapConfig $config, string $userDn): array
    {
        $defaults = ['name' => '', 'email' => '', 'firstname' => '', 'lastname' => ''];

        $conn = $this->connect($config);
        if ($conn === null) {
            return $defaults;
        }

        if ($config->bindDn() !== '') {
            if (!@ldap_bind($conn, $config->bindDn(), $config->bindPassword())) {
                ldap_unbind($conn);

                return $defaults;
            }
        }

        $search = @ldap_read($conn, $userDn, '(objectClass=*)', ['cn', 'mail', 'givenName', 'sn', 'displayName']);
        if ($search === false || \is_array($search)) {
            ldap_unbind($conn);

            return $defaults;
        }

        $entries = ldap_get_entries($conn, $search);
        ldap_unbind($conn);

        if ($entries === false || $entries['count'] === 0) {
            return $defaults;
        }

        if (!isset($entries[0]) || !\is_array($entries[0])) {
            return $defaults;
        }

        $entry = $entries[0];

        return [
            'name' => self::firstValue($entry, 'displayname', self::firstValue($entry, 'cn')),
            'email' => self::firstValue($entry, 'mail'),
            'firstname' => self::firstValue($entry, 'givenname'),
            'lastname' => self::firstValue($entry, 'sn'),
        ];
    }

    /**
     * Reads the first value of an LDAP attribute.
     *
     * ldap_get_entries() is typed as a plain array, so every hop -- $entries[0],
     * $entry[$key], $entry[$key][0] -- is mixed. Each one has to be guarded
     * where it is read: testing `$entry[$key][0] ?? null` refines that
     * expression, not the offset, so the value stays mixed on the way out.
     *
     * @param array<array-key, mixed> $entry
     */
    private static function firstValue(array $entry, string $key, string $default = ''): string
    {
        if (!isset($entry[$key]) || !\is_array($entry[$key])) {
            return $default;
        }

        $values = $entry[$key];

        return isset($values[0]) && \is_string($values[0]) ? $values[0] : $default;
    }

    /**
     * @param array{name: string, email: string, firstname: string, lastname: string} $attrs
     */
    private function provisionOrSync(string $username, array $attrs): ?User
    {
        $existing = $this->userService->getUserByLogin($username);

        $firstName = $attrs['firstname'] !== '' ? $attrs['firstname'] : ($attrs['name'] !== '' ? $attrs['name'] : $username);
        $lastName = $attrs['lastname'];
        $email = $attrs['email'];

        if ($existing !== null) {
            // Sync attributes on subsequent logins
            $updated = new User(
                login: $existing->login(),
                firstName: $firstName,
                lastName: $lastName,
                email: $email !== '' ? $email : $existing->email(),
                isAdmin: $existing->isAdmin(),
                isEnabled: $existing->isEnabled(),
            );

            try {
                $this->userService->updateUser($updated, $updated);
            } catch (\Throwable) {
                // Non-fatal — user still authenticated
            }

            return $this->userService->getUserByLogin($username);
        }

        // Auto-provision new user
        $newUser = new User(
            login: $username,
            firstName: $firstName,
            lastName: $lastName,
            email: $email,
            isAdmin: false,
            isEnabled: true,
        );

        try {
            $this->userService->createUser($newUser, $newUser);
            // Set random password (user authenticates via LDAP)
            $hash = $this->userService->hashPassword(bin2hex(random_bytes(32)));
            $this->userRepository->setPassword($username, $hash);

            return $this->userService->getUserByLogin($username);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return \LDAP\Connection|null
     */
    private function connect(LdapConfig $config): mixed
    {
        $uri = ($config->useTls() ? 'ldaps://' : 'ldap://') . $config->host() . ':' . $config->port();

        $conn = @ldap_connect($uri);
        if ($conn === false) {
            return null;
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

        return $conn;
    }
}
