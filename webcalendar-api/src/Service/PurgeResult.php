<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Immutable result of a PurgeService::purge() call.
 */
final readonly class PurgeResult
{
    public function __construct(
        public int $count,
        public bool $dryRun,
        public \DateTimeImmutable $beforeDate,
        public ?string $userLogin,
    ) {}

    /**
     * @return array{count: int, dry_run: bool, before_date: string, user_login: ?string}
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'dry_run' => $this->dryRun,
            'before_date' => $this->beforeDate->format('Y-m-d'),
            'user_login' => $this->userLogin,
        ];
    }
}
