<?php

declare(strict_types=1);

namespace App\Auth;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use WebCalendar\Core\Application\Service\GroupService;
use WebCalendar\Core\Domain\Entity\Group;

/**
 * Syncs LDAP group membership to webcalendar groups.
 *
 * On user login, queries LDAP for the user's group membership (memberOf)
 * and ensures matching webcalendar groups exist with correct membership.
 */
final class LdapGroupSync
{
    private ClockInterface $clock;

    public function __construct(
        private readonly LdapConfigRepository $configRepo,
        private readonly GroupService $groupService,
        ?ClockInterface $clock = null,
        // Defaulted like LdapAuthenticator's, so existing construction keeps
        // working and tests can drive the directory without a server.
        private readonly LdapClient $ldap = new ExtLdapClient(),
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Syncs LDAP groups for a user after successful authentication.
     *
     * @param string $username The webcalendar username
     * @param string $userDn The user's LDAP DN
     *
     * @return list<string> List of synced group names
     */
    public function syncUserGroups(string $username, string $userDn): array
    {
        $config = $this->configRepo->get();
        if (!$config->isEnabled() || $config->host() === '' || !$this->ldap->isSupported()) {
            return [];
        }

        $memberOfGroups = $this->fetchMemberOfGroups($config, $userDn);
        if ($memberOfGroups === []) {
            return [];
        }

        $syncedGroups = [];

        foreach ($memberOfGroups as $groupDn) {
            $groupName = $this->extractGroupName($groupDn);
            if ($groupName === '') {
                continue;
            }

            // Find or create the webcalendar group
            $existingGroups = $this->groupService->getAllGroups();
            $found = false;
            foreach ($existingGroups as $g) {
                if ($g->name() === $groupName) {
                    $found = true;
                    // Add member if not already
                    $members = $this->groupService->getGroupMembers($g->id());
                    if (!\in_array($username, $members, true)) {
                        $this->groupService->addMember($g->id(), $username);
                    }
                    break;
                }
            }

            if (!$found) {
                // Create group
                $id = random_int(100000, 2147483000);
                $group = new Group(
                    id: $id,
                    owner: 'admin',
                    name: $groupName,
                    lastUpdate: $this->clock->now(),
                );
                $this->groupService->createGroup($group);
                $this->groupService->addMember($id, $username);
            }

            $syncedGroups[] = $groupName;
        }

        return $syncedGroups;
    }

    /**
     * @return list<string> List of group DNs
     */
    private function fetchMemberOfGroups(LdapConfig $config, string $userDn): array
    {
        $scheme = $config->useTls() ? 'ldaps://' : 'ldap://';
        $connection = $this->ldap->connect($scheme . $config->host() . ':' . $config->port());

        if ($connection === null) {
            return [];
        }

        // Same shape as LdapAuthenticator's service bind. Left inline rather
        // than shared: two call sites in one class there, one here, and a
        // common home for it would have to take LdapConfig, which the adapter
        // deliberately knows nothing about.
        if ($config->bindDn() !== '' && !$connection->bind($config->bindDn(), $config->bindPassword())) {
            $connection->close();

            return [];
        }

        $entries = $connection->read($userDn, '(objectClass=*)', ['memberOf']);
        $connection->close();

        if ($entries === null || $entries['count'] === 0) {
            return [];
        }

        $groups = [];
        /** @var array<string, mixed> $entry */
        $entry = $entries[0];
        if (isset($entry['memberof']) && \is_array($entry['memberof'])) {
            $count = \is_int($entry['memberof']['count'] ?? null) ? $entry['memberof']['count'] : 0;
            for ($i = 0; $i < $count; $i++) {
                if (\is_string($entry['memberof'][$i] ?? null)) {
                    $groups[] = $entry['memberof'][$i];
                }
            }
        }

        return $groups;
    }

    /**
     * Extracts the CN (Common Name) from an LDAP DN.
     * "CN=Engineering,OU=Groups,DC=corp,DC=com" → "Engineering"
     */
    private function extractGroupName(string $dn): string
    {
        if (preg_match('/^CN=([^,]+)/i', $dn, $m)) {
            return $m[1];
        }

        return '';
    }
}
