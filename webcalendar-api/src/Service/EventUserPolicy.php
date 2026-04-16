<?php

declare(strict_types=1);

namespace App\Service;

use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

final readonly class EventUserPolicy
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {}

    /**
     * Checks if a user requires admin approval for new events.
     */
    public function requiresApproval(string $login): bool
    {
        $prefs = $this->userRepository->getPreferences($login);
        foreach ($prefs as $pref) {
            if ($pref->key() === 'require_event_approval' && $pref->value() === 'Y') {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the user's conflict detection mode preference.
     * Values: 'warn' (default), 'block', 'off'
     */
    public function getConflictMode(string $login): string
    {
        $prefs = $this->userRepository->getPreferences($login);
        foreach ($prefs as $pref) {
            if ($pref->key() === 'conflict_mode') {
                $value = $pref->value();
                if (\in_array($value, ['warn', 'block', 'off'], true)) {
                    return $value;
                }
            }
        }
        return 'warn';
    }
}
