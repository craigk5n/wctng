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
        // Defaulted so the container and any direct construction keep working;
        // tests pass a fake to drive the paths ext-ldap otherwise hides.
        private readonly LdapClient $ldap = new ExtLdapClient(),
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

        if (!$this->ldap->isSupported()) {
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

        return $config->isEnabled() && $config->host() !== '' && $this->ldap->isSupported();
    }

    private function findUserDn(LdapConfig $config, string $username): ?string
    {
        $connection = $this->connect($config);
        if ($connection === null) {
            return null;
        }

        if (!$this->bindServiceAccount($config, $connection)) {
            return null;
        }

        $filter = sprintf($config->userFilter(), $this->ldap->escapeFilterValue($username));
        $entries = $connection->search($config->baseDn(), $filter, ['dn']);
        $connection->close();

        // Exactly one match: zero means no such user, more than one means the
        // filter is ambiguous and picking either would be a guess.
        if ($entries === null || $entries['count'] !== 1) {
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
        $connection = $this->connect($config);
        if ($connection === null) {
            return false;
        }

        $result = $connection->bind($userDn, $password);
        $connection->close();

        return $result;
    }

    /**
     * @return array{name: string, email: string, firstname: string, lastname: string}
     */
    private function fetchUserAttributes(LdapConfig $config, string $userDn): array
    {
        $defaults = ['name' => '', 'email' => '', 'firstname' => '', 'lastname' => ''];

        $connection = $this->connect($config);
        if ($connection === null) {
            return $defaults;
        }

        if (!$this->bindServiceAccount($config, $connection)) {
            return $defaults;
        }

        $entries = $connection->read($userDn, '(objectClass=*)', ['cn', 'mail', 'givenName', 'sn', 'displayName']);
        $connection->close();

        if ($entries === null || $entries['count'] === 0) {
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

        // Auto-provision new user. Inside the try because the User constructor
        // rejects an empty email, and a directory entry without a mail
        // attribute should deny the login rather than raise a 500.
        try {
            $newUser = new User(
                login: $username,
                firstName: $firstName,
                lastName: $lastName,
                email: $email,
                isAdmin: false,
                isEnabled: true,
            );

            // Straight to the repository, not UserService::createUser(): that
            // authorises the action against the acting user, and the only user
            // available to pass here is the non-admin being created, so it
            // always threw "Admin privileges required" -- silently, into the
            // catch below. Provisioning is a system action with no actor.
            $this->userRepository->save($newUser);

            // Set random password (user authenticates via LDAP)
            $hash = $this->userService->hashPassword(bin2hex(random_bytes(32)));
            $this->userRepository->setPassword($username, $hash);

            return $this->userService->getUserByLogin($username);
        } catch (\Throwable) {
            return null;
        }
    }

    private function connect(LdapConfig $config): ?LdapConnection
    {
        $scheme = $config->useTls() ? 'ldaps://' : 'ldap://';

        return $this->ldap->connect($scheme . $config->host() . ':' . $config->port());
    }

    /**
     * Binds as the configured service account, or leaves the connection
     * anonymous when none is configured. Closes on failure so callers can
     * simply return their empty result.
     */
    private function bindServiceAccount(LdapConfig $config, LdapConnection $connection): bool
    {
        if ($config->bindDn() === '') {
            return true;
        }

        if ($connection->bind($config->bindDn(), $config->bindPassword())) {
            return true;
        }

        $connection->close();

        return false;
    }
}
