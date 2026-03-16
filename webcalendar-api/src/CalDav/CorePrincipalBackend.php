<?php

declare(strict_types=1);

namespace App\CalDav;

use App\Service\CoreServiceFactory;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Maps webcalendar users to CalDAV principals.
 *
 * Principals live at /principals/{username} and reference
 * calendar-home-set at /calendars/{username}/.
 */
final class CorePrincipalBackend implements BackendInterface
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getPrincipalsByPrefix($prefixPath): array
    {
        if ($prefixPath !== 'principals') {
            return [];
        }

        $principals = [];

        try {
            $users = $this->coreServiceFactory->getUserRepository()->findAll();

            foreach ($users as $user) {
                $principals[] = $this->userToPrincipal($user);
            }
        } catch (\Throwable) {
            // Return empty if DB not available
        }

        return $principals;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getPrincipalByPath($path): array
    {
        $parts = explode('/', (string) $path);
        if (\count($parts) !== 2 || $parts[0] !== 'principals') {
            return [];
        }

        $login = $parts[1];
        $user = $this->coreServiceFactory->getUserService()->getUserByLogin($login);

        if ($user === null) {
            return [];
        }

        return $this->userToPrincipal($user);
    }

    #[\Override]
    public function updatePrincipal($path, PropPatch $propPatch): void
    {
        // Read-only principals
    }

    /**
     * @param array<string, mixed> $searchProperties
     *
     * @return list<string>
     */
    #[\Override]
    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof'): array
    {
        $results = [];
        $principals = $this->getPrincipalsByPrefix((string) $prefixPath);

        foreach ($principals as $principal) {
            $match = true;
            foreach ($searchProperties as $prop => $value) {
                /** @var string $propVal */
                $propVal = $principal[$prop] ?? '';
                /** @var string $searchVal */
                $searchVal = $value;
                if ($propVal !== '' && stripos($propVal, $searchVal) === false) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                /** @var string $uri */
                $uri = $principal['uri'];
                $results[] = $uri;
            }
        }

        return $results;
    }

    #[\Override]
    public function findByUri($uri, $principalPrefix): ?string
    {
        /** @var string $uriStr */
        $uriStr = $uri;
        /** @var string $prefix */
        $prefix = $principalPrefix;

        if (str_starts_with($uriStr, 'mailto:')) {
            $email = substr($uriStr, 7);
            $principals = $this->getPrincipalsByPrefix($prefix);
            foreach ($principals as $principal) {
                if (($principal['{http://sabredav.org/ns}email-address'] ?? '') === $email) {
                    /** @var string $pUri */
                    $pUri = $principal['uri'];
                    return $pUri;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getGroupMemberSet($principal): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getGroupMembership($principal): array
    {
        return [];
    }

    /**
     * @param list<string> $members
     */
    #[\Override]
    public function setGroupMemberSet($principal, array $members): void
    {
        // Not supported
    }

    /**
     * @return array<string, mixed>
     */
    private function userToPrincipal(User $user): array
    {
        $displayName = trim($user->firstName() . ' ' . $user->lastName());

        return [
            'uri' => 'principals/' . $user->login(),
            '{DAV:}displayname' => $displayName !== '' ? $displayName : $user->login(),
            '{http://sabredav.org/ns}email-address' => $user->email(),
            '{urn:ietf:params:xml:ns:caldav}calendar-home-set' => 'calendars/' . $user->login() . '/',
        ];
    }
}
