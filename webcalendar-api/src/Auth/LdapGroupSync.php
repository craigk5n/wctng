<?php

declare(strict_types=1);

namespace App\Auth;

use App\Service\CoreServiceFactory;
use WebCalendar\Core\Domain\Entity\Group;

/**
 * Syncs LDAP group membership to webcalendar groups.
 *
 * On user login, queries LDAP for the user's group membership (memberOf)
 * and ensures matching webcalendar groups exist with correct membership.
 */
final class LdapGroupSync
{
    public function __construct(
        private readonly LdapConfigRepository $configRepo,
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
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
        if (!$config->isEnabled() || $config->host() === '' || !\function_exists('ldap_connect')) {
            return [];
        }

        $memberOfGroups = $this->fetchMemberOfGroups($config, $userDn);
        if ($memberOfGroups === []) {
            return [];
        }

        $syncedGroups = [];
        $groupService = $this->coreServiceFactory->getGroupService();

        foreach ($memberOfGroups as $groupDn) {
            $groupName = $this->extractGroupName($groupDn);
            if ($groupName === '') {
                continue;
            }

            // Find or create the webcalendar group
            $existingGroups = $groupService->getAllGroups();
            $found = false;
            foreach ($existingGroups as $g) {
                if ($g->name() === $groupName) {
                    $found = true;
                    // Add member if not already
                    $members = $groupService->getGroupMembers($g->id());
                    if (!\in_array($username, $members, true)) {
                        $groupService->addMember($g->id(), $username);
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
                    lastUpdate: new \DateTimeImmutable(),
                );
                $groupService->createGroup($group);
                $groupService->addMember($id, $username);
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
        $uri = ($config->useTls() ? 'ldaps://' : 'ldap://') . $config->host() . ':' . $config->port();
        $conn = @ldap_connect($uri);
        if ($conn === false) {
            return [];
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

        if ($config->bindDn() !== '') {
            if (!@ldap_bind($conn, $config->bindDn(), $config->bindPassword())) {
                ldap_unbind($conn);

                return [];
            }
        }

        $search = @ldap_read($conn, $userDn, '(objectClass=*)', ['memberOf']);
        if ($search === false || \is_array($search)) {
            ldap_unbind($conn);

            return [];
        }

        $entries = ldap_get_entries($conn, $search);
        ldap_unbind($conn);

        if ($entries === false || $entries['count'] === 0) {
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
