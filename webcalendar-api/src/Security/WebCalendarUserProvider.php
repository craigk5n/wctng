<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\CoreServiceFactory;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads users from webcalendar-core's UserService for Symfony Security.
 *
 * @implements UserProviderInterface<WebCalendarUser>
 */
final class WebCalendarUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {}

    #[\Override]
    public function loadUserByIdentifier(string $identifier): WebCalendarUser
    {
        $coreUser = $this->coreServiceFactory->getUserService()->getUserByLogin($identifier);

        if ($coreUser === null) {
            $exception = new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
            $exception->setUserIdentifier($identifier);
            throw $exception;
        }

        $passwordHash = $this->coreServiceFactory->getUserRepository()->getPasswordHash($identifier);

        return new WebCalendarUser($coreUser, $passwordHash);
    }

    #[\Override]
    public function refreshUser(UserInterface $user): WebCalendarUser
    {
        if (!$user instanceof WebCalendarUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    #[\Override]
    public function supportsClass(string $class): bool
    {
        return $class === WebCalendarUser::class;
    }
}
