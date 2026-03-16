<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Symfony Security user wrapping a webcalendar-core User entity.
 */
final class WebCalendarUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private readonly User $coreUser,
        private readonly ?string $passwordHash,
    ) {
    }

    /**
     * @return non-empty-string
     */
    #[\Override]
    public function getUserIdentifier(): string
    {
        $login = $this->coreUser->login();
        \assert($login !== '');

        return $login;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];

        if ($this->coreUser->isAdmin()) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }

    #[\Override]
    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    #[\Override]
    public function eraseCredentials(): void
    {
        // No sensitive data stored in memory to clear
    }

    public function getCoreUser(): User
    {
        return $this->coreUser;
    }
}
