<?php

declare(strict_types=1);

namespace App\Auth;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WebCalendar\Core\Application\Contract\AuthServiceInterface;
use WebCalendar\Core\Application\Service\UserService;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Tries multiple authentication providers in priority order.
 * First successful auth wins (short-circuit).
 */
final class ChainedAuthenticator
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly AuthServiceInterface $authService,
        private readonly UserService $userService,
        private readonly LdapAuthenticator $ldapAuth,
        private readonly AuthProviderRegistry $registry,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Tries authentication providers in priority order.
     *
     * @return array{user: User, method: string}|null
     */
    public function authenticate(string $username, #[\SensitiveParameter] string $password): ?array
    {
        $providers = $this->registry->getEnabledProviders();

        foreach ($providers as $provider) {
            $type = $provider['type'];

            try {
                $user = match ($type) {
                    'ldap' => $this->ldapAuth->authenticate($username, $password),
                    'password' => $this->tryPasswordAuth($username, $password),
                    default => null, // OAuth/OIDC handled via redirect flow, not here
                };

                if ($user !== null) {
                    $this->logger->info('Authentication successful', ['user' => $username, 'method' => $type]);

                    return ['user' => $user, 'method' => $type];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Auth provider failed', ['type' => $type, 'error' => $e->getMessage()]);
            }
        }

        $this->logger->info('All auth providers failed', ['user' => $username]);

        return null;
    }

    private function tryPasswordAuth(string $username, #[\SensitiveParameter] string $password): ?User
    {
        if (!$this->authService->authenticate($username, $password)) {
            return null;
        }

        return $this->userService->getUserByLogin($username);
    }
}
