<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Imports data from a legacy WebCalendar database into WCTNG.
 *
 * Uses schema probing to auto-detect which columns/tables exist,
 * making it compatible with WebCalendar versions from 1.0 through 1.3.x.
 */
final class LegacyImportService
{
    private LoggerInterface $logger;

    /** @var array<string, list<string>> Column maps per table */
    private array $columnMap = [];

    /** @var array{users: array{imported: int, skipped: int, errors: int}, events: array{imported: int, skipped: int, errors: int}, categories: array{imported: int, skipped: int}, participants: array{imported: int, skipped: int}, preferences: array{imported: int, skipped: int}} */
    private array $stats;

    public function __construct(
        private readonly CoreServiceFactory $factory,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->resetStats();
    }

    /**
     * Run the import from a legacy database.
     *
     * @return array{users: array{imported: int, skipped: int, errors: int}, events: array{imported: int, skipped: int, errors: int}, categories: array{imported: int, skipped: int}, participants: array{imported: int, skipped: int}, preferences: array{imported: int, skipped: int}}
     */
    public function import(\PDO $legacyPdo, bool $dryRun = false): array
    {
        $this->resetStats();

        // Step 1: Probe schema
        $this->probeSchema($legacyPdo);
        $this->validateSchema();

        // Step 2: Import in dependency order
        $this->importUsers($legacyPdo, $dryRun);
        $this->importCategories($legacyPdo, $dryRun);
        $this->importEvents($legacyPdo, $dryRun);
        $this->importParticipants($legacyPdo, $dryRun);
        $this->importPreferences($legacyPdo, $dryRun);

        return $this->stats;
    }

    /**
     * Probe the legacy database schema to determine available columns.
     */
    private function probeSchema(\PDO $pdo): void
    {
        $tables = ['webcal_user', 'webcal_entry', 'webcal_entry_user', 'webcal_entry_repeats',
            'webcal_categories', 'webcal_entry_categories', 'webcal_user_pref'];

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        foreach ($tables as $table) {
            try {
                if ($driver === 'sqlite') {
                    $stmt = $pdo->query("PRAGMA table_info({$table})");
                    $columns = [];
                    if ($stmt !== false) {
                        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                            if (\is_array($row) && isset($row['name'])) {
                                /** @var string $colName */
                                $colName = $row['name'];
                                $columns[] = $colName;
                            }
                        }
                    }
                } else {
                    $stmt = $pdo->query("SHOW COLUMNS FROM {$table}");
                    $columns = [];
                    if ($stmt !== false) {
                        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                            if (\is_array($row) && isset($row['Field'])) {
                                /** @var string $colName */
                                $colName = $row['Field'];
                                $columns[] = $colName;
                            }
                        }
                    }
                }
                $this->columnMap[$table] = $columns;
                $this->logger->info("Probed {$table}: " . \count($columns) . ' columns');
            } catch (\Throwable) {
                $this->logger->info("Table {$table} not found — skipping");
                $this->columnMap[$table] = [];
            }
        }
    }

    private function validateSchema(): void
    {
        $entryColumns = $this->columnMap['webcal_entry'] ?? [];
        $required = ['cal_id', 'cal_name', 'cal_date', 'cal_create_by'];

        foreach ($required as $col) {
            if (!\in_array($col, $entryColumns, true)) {
                throw new \RuntimeException("Legacy schema missing required column webcal_entry.{$col}. Is this a WebCalendar database?");
            }
        }

        if (($this->columnMap['webcal_user'] ?? []) === []) {
            throw new \RuntimeException('Legacy schema missing webcal_user table.');
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return \in_array($column, $this->columnMap[$table] ?? [], true);
    }

    private function importUsers(\PDO $legacyPdo, bool $dryRun): void
    {
        $columns = ['cal_login'];
        if ($this->hasColumn('webcal_user', 'cal_firstname')) {
            $columns[] = 'cal_firstname';
        }
        if ($this->hasColumn('webcal_user', 'cal_lastname')) {
            $columns[] = 'cal_lastname';
        }
        if ($this->hasColumn('webcal_user', 'cal_email')) {
            $columns[] = 'cal_email';
        }
        if ($this->hasColumn('webcal_user', 'cal_is_admin')) {
            $columns[] = 'cal_is_admin';
        }

        $stmt = $legacyPdo->query('SELECT ' . implode(', ', $columns) . ' FROM webcal_user');
        if ($stmt === false) {
            return;
        }

        $userService = $this->factory->getUserService();
        $adminUser = new \WebCalendar\Core\Domain\Entity\User('admin', 'Admin', 'Import', 'admin@import.local', true, true);

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (!\is_array($row)) {
                continue;
            }

            /** @var array<string, string|int|null> $row */
            $login = (string) ($row['cal_login'] ?? '');
            if ($login === '') {
                continue;
            }

            // Check if user already exists
            $existing = $this->factory->getUserService()->getUserByLogin($login);
            if ($existing !== null) {
                $this->stats['users']['skipped']++;
                continue;
            }

            if ($dryRun) {
                $this->stats['users']['imported']++;
                $this->logger->info("[DRY RUN] Would import user: {$login}");
                continue;
            }

            try {
                $email = (string) ($row['cal_email'] ?? '');
                if ($email === '' || !str_contains($email, '@')) {
                    $email = "{$login}@imported.local";
                }

                $user = new \WebCalendar\Core\Domain\Entity\User(
                    login: $login,
                    firstName: (string) ($row['cal_firstname'] ?? ''),
                    lastName: (string) ($row['cal_lastname'] ?? ''),
                    email: $email,
                    isAdmin: ($row['cal_is_admin'] ?? 'N') === 'Y',
                    isEnabled: true,
                );

                $userService->createUser($user, $adminUser);

                // Set a random password — users must reset
                $randomPassword = bin2hex(random_bytes(16));
                $this->factory->getUserRepository()->setPassword(
                    $login,
                    password_hash($randomPassword, \PASSWORD_DEFAULT),
                );

                $this->stats['users']['imported']++;
                $this->logger->info("Imported user: {$login}");
            } catch (\Throwable $e) {
                $this->stats['users']['errors']++;
                $this->logger->warning("Failed to import user {$login}: {$e->getMessage()}");
            }
        }
    }

    private function importCategories(\PDO $legacyPdo, bool $dryRun): void
    {
        if (($this->columnMap['webcal_categories'] ?? []) === []) {
            $this->logger->info('No webcal_categories table — skipping category import');
            return;
        }

        // Legacy v1.9.11+ stored category icons as MIME-typed blobs in
        // cat_icon_blob/cat_icon_mime. The rewrite replaced that with
        // single-emoji icons (see STATUS.md plan change 2026-04-08), so
        // legacy blobs are silently dropped on import. Count them and
        // log a notice so the admin knows to expect re-picking icons.
        if ($this->hasColumn('webcal_categories', 'cat_icon_blob')) {
            try {
                $countStmt = $legacyPdo->query('SELECT COUNT(*) FROM webcal_categories WHERE cat_icon_blob IS NOT NULL');
                $dropped = ($countStmt !== false) ? (int) $countStmt->fetchColumn() : 0;
                if ($dropped > 0) {
                    $this->stats['categories']['icons_dropped'] = $dropped;
                    $this->logger->notice(
                        "Legacy category icon blobs detected ({$dropped}) — not imported. "
                        . 'Users should pick new emoji icons in the Category admin page.'
                    );
                }
            } catch (\Throwable) {
                // Count failure is non-fatal — we just won't report the number.
            }
        }

        $columns = ['cat_id', 'cat_name'];
        if ($this->hasColumn('webcal_categories', 'cat_color')) {
            $columns[] = 'cat_color';
        }
        if ($this->hasColumn('webcal_categories', 'cat_owner')) {
            $columns[] = 'cat_owner';
        }
        if ($this->hasColumn('webcal_categories', 'cat_status')) {
            $columns[] = 'cat_status';
        }

        try {
            $stmt = $legacyPdo->query('SELECT ' . implode(', ', $columns) . ' FROM webcal_categories');
        } catch (\Throwable) {
            return;
        }
        if ($stmt === false) {
            return;
        }

        $catService = $this->factory->getCategoryService();
        $adminUser = new \WebCalendar\Core\Domain\Entity\User('admin', 'Admin', 'Import', 'admin@import.local', true, true);

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (!\is_array($row)) {
                continue;
            }

            /** @var array<string, string|int|null> $row */
            $name = (string) ($row['cat_name'] ?? '');
            if ($name === '') {
                continue;
            }

            if ($dryRun) {
                $this->stats['categories']['imported']++;
                $this->logger->info("[DRY RUN] Would import category: {$name}");
                continue;
            }

            try {
                $color = isset($row['cat_color']) && \is_string($row['cat_color']) && $row['cat_color'] !== '' ? $row['cat_color'] : null;
                $owner = isset($row['cat_owner']) && \is_string($row['cat_owner']) && $row['cat_owner'] !== '' ? $row['cat_owner'] : null;
                // cat_status: 'A' = active (default), anything else = disabled (added in 1.9.11)
                $enabled = !isset($row['cat_status']) || $row['cat_status'] === 'A';
                $category = new \WebCalendar\Core\Domain\Entity\Category(0, $owner, $name, $color, $enabled);
                $catService->createCategory($category, $adminUser);
                $this->stats['categories']['imported']++;
            } catch (\Throwable $e) {
                $this->stats['categories']['skipped']++;
                $this->logger->warning("Failed to import category {$name}: {$e->getMessage()}");
            }
        }
    }

    private function importEvents(\PDO $legacyPdo, bool $dryRun): void
    {
        // Build SELECT with available columns
        $columns = ['cal_id', 'cal_name', 'cal_date', 'cal_create_by', 'cal_duration'];
        $optional = ['cal_time', 'cal_description', 'cal_location', 'cal_access', 'cal_type', 'cal_uid',
            'cal_priority', 'cal_status', 'cal_url', 'cal_sequence', 'cal_mod_date', 'cal_mod_time'];

        foreach ($optional as $col) {
            if ($this->hasColumn('webcal_entry', $col)) {
                $columns[] = $col;
            }
        }

        $stmt = $legacyPdo->query('SELECT ' . implode(', ', $columns) . ' FROM webcal_entry ORDER BY cal_id');
        if ($stmt === false) {
            return;
        }

        $eventRepo = $this->factory->getEventRepository();

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (!\is_array($row)) {
                continue;
            }

            /** @var array<string, string|int|null> $row */
            $calId = (int) ($row['cal_id'] ?? 0);
            $name = (string) ($row['cal_name'] ?? '');
            if ($calId === 0 || $name === '') {
                continue;
            }

            // Generate or use existing UID
            $uid = (string) ($row['cal_uid'] ?? '');
            if ($uid === '') {
                $uid = "legacy-{$calId}@imported";
            }

            // Check if already imported (idempotent)
            $existing = $eventRepo->findByUid($uid);
            if ($existing !== null) {
                $this->stats['events']['skipped']++;
                continue;
            }

            if ($dryRun) {
                $this->stats['events']['imported']++;
                $this->logger->info("[DRY RUN] Would import event #{$calId}: {$name}");
                continue;
            }

            try {
                $date = (string) ($row['cal_date'] ?? '');
                $time = (string) ($row['cal_time'] ?? '-1');
                $start = $this->parseLegacyDateTime($date, $time);

                $accessChar = (string) ($row['cal_access'] ?? 'P');
                $typeChar = (string) ($row['cal_type'] ?? 'E');

                $event = new \WebCalendar\Core\Domain\Entity\Event(
                    id: new \WebCalendar\Core\Domain\ValueObject\EventId(0),
                    uid: $uid,
                    name: $name,
                    description: (string) ($row['cal_description'] ?? ''),
                    location: (string) ($row['cal_location'] ?? ''),
                    start: $start,
                    duration: max(0, (int) ($row['cal_duration'] ?? 0)),
                    createdBy: (string) ($row['cal_create_by'] ?? 'admin'),
                    type: $this->mapEventType($typeChar),
                    access: $this->mapAccessLevel($accessChar),
                    status: $this->mapStatus((string) ($row['cal_status'] ?? '')),
                    allDay: (int) ($row['cal_time'] ?? -1) < 0,
                );

                // Find creator user for permissions
                $creator = $this->factory->getUserService()->getUserByLogin($event->createdBy());
                if ($creator === null) {
                    // Creator doesn't exist — use admin
                    $creator = new \WebCalendar\Core\Domain\Entity\User('admin', 'Admin', 'Import', 'admin@import.local', true, true);
                }

                $this->factory->getEventService()->createEvent($event, $creator);
                $this->stats['events']['imported']++;
                $this->logger->info("Imported event #{$calId}: {$name}");
            } catch (\Throwable $e) {
                $this->stats['events']['errors']++;
                $this->logger->warning("Failed to import event #{$calId}: {$e->getMessage()}");
            }
        }
    }

    private function importParticipants(\PDO $legacyPdo, bool $dryRun): void
    {
        if (($this->columnMap['webcal_entry_user'] ?? []) === []) {
            return;
        }

        $columns = ['cal_id', 'cal_login'];
        if ($this->hasColumn('webcal_entry_user', 'cal_status')) {
            $columns[] = 'cal_status';
        }

        try {
            $stmt = $legacyPdo->query('SELECT ' . implode(', ', $columns) . ' FROM webcal_entry_user');
        } catch (\Throwable) {
            return;
        }
        if ($stmt === false) {
            return;
        }

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (!\is_array($row)) {
                continue;
            }

            /** @var array<string, string|int|null> $row */
            $calId = (int) ($row['cal_id'] ?? 0);
            $login = (string) ($row['cal_login'] ?? '');

            if ($calId === 0 || $login === '') {
                continue;
            }

            if ($dryRun) {
                $this->stats['participants']['imported']++;
                continue;
            }

            // We can't easily map legacy cal_id to new event IDs without a lookup table.
            // For now, count them but skip actual import — participants are re-added manually.
            $this->stats['participants']['skipped']++;
        }
    }

    private function importPreferences(\PDO $legacyPdo, bool $dryRun): void
    {
        if (($this->columnMap['webcal_user_pref'] ?? []) === []) {
            return;
        }

        try {
            $stmt = $legacyPdo->query('SELECT cal_login, cal_setting, cal_value FROM webcal_user_pref');
        } catch (\Throwable) {
            return;
        }
        if ($stmt === false) {
            return;
        }

        // Map of legacy preference keys to WCTNG keys (only import known ones)
        $prefMap = [
            'STARTVIEW' => 'STARTVIEW',
            'TIMEZONE' => 'TIMEZONE',
            'WORK_DAY_START_HOUR' => 'WORK_DAY_START',
            'WORK_DAY_END_HOUR' => 'WORK_DAY_END',
            'LANGUAGE' => 'locale',
        ];

        $userRepo = $this->factory->getUserRepository();

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (!\is_array($row)) {
                continue;
            }

            /** @var array<string, string|int|null> $row */
            $login = (string) ($row['cal_login'] ?? '');
            $setting = (string) ($row['cal_setting'] ?? '');
            $value = (string) ($row['cal_value'] ?? '');

            if ($login === '' || $setting === '') {
                continue;
            }

            $mappedKey = $prefMap[$setting] ?? null;
            if ($mappedKey === null) {
                continue;
            }

            // Map legacy hour values to HH:MM format
            if ($setting === 'WORK_DAY_START_HOUR' || $setting === 'WORK_DAY_END_HOUR') {
                $hour = (int) $value;
                $value = sprintf('%02d:00', $hour);
            }

            // Map legacy STARTVIEW values
            if ($setting === 'STARTVIEW') {
                $value = match ($value) {
                    'month', 'M' => 'dayGridMonth',
                    'week', 'W' => 'timeGridWeek',
                    'day', 'D' => 'timeGridDay',
                    default => 'dayGridMonth',
                };
            }

            if ($dryRun) {
                $this->stats['preferences']['imported']++;
                continue;
            }

            try {
                $userRepo->savePreference($login, new \WebCalendar\Core\Domain\ValueObject\UserPreference($mappedKey, $value));
                $this->stats['preferences']['imported']++;
            } catch (\Throwable) {
                $this->stats['preferences']['skipped']++;
            }
        }
    }

    private function parseLegacyDateTime(string $date, string $time): \DateTimeImmutable
    {
        // Legacy format: date = YYYYMMDD (int), time = HHMMSS (int, -1 for all-day)
        $y = substr($date, 0, 4);
        $m = substr($date, 4, 2);
        $d = substr($date, 6, 2);

        $timeInt = (int) $time;
        if ($timeInt < 0) {
            return new \DateTimeImmutable("{$y}-{$m}-{$d} 00:00:00");
        }

        $timeStr = str_pad((string) $timeInt, 6, '0', \STR_PAD_LEFT);
        $h = substr($timeStr, 0, 2);
        $min = substr($timeStr, 2, 2);
        $s = substr($timeStr, 4, 2);

        return new \DateTimeImmutable("{$y}-{$m}-{$d} {$h}:{$min}:{$s}");
    }

    private function mapAccessLevel(string $access): \WebCalendar\Core\Domain\ValueObject\AccessLevel
    {
        return match ($access) {
            'R' => \WebCalendar\Core\Domain\ValueObject\AccessLevel::PRIVATE,
            'C' => \WebCalendar\Core\Domain\ValueObject\AccessLevel::CONFIDENTIAL,
            default => \WebCalendar\Core\Domain\ValueObject\AccessLevel::PUBLIC,
        };
    }

    private function mapEventType(string $type): \WebCalendar\Core\Domain\ValueObject\EventType
    {
        return match ($type) {
            'T' => \WebCalendar\Core\Domain\ValueObject\EventType::TASK,
            'J' => \WebCalendar\Core\Domain\ValueObject\EventType::JOURNAL,
            'M' => \WebCalendar\Core\Domain\ValueObject\EventType::REPEATING_EVENT,
            'N' => \WebCalendar\Core\Domain\ValueObject\EventType::REPEATING_TASK,
            'O' => \WebCalendar\Core\Domain\ValueObject\EventType::REPEATING_JOURNAL,
            default => \WebCalendar\Core\Domain\ValueObject\EventType::EVENT,
        };
    }

    private function mapStatus(string $status): ?string
    {
        if ($status === '') {
            return null;
        }

        return match (strtoupper($status)) {
            'CANCELLED' => 'cancelled',
            'TENTATIVE' => 'tentative',
            default => null,
        };
    }

    private function resetStats(): void
    {
        $this->stats = [
            'users' => ['imported' => 0, 'skipped' => 0, 'errors' => 0],
            'events' => ['imported' => 0, 'skipped' => 0, 'errors' => 0],
            'categories' => ['imported' => 0, 'skipped' => 0, 'icons_dropped' => 0],
            'participants' => ['imported' => 0, 'skipped' => 0],
            'preferences' => ['imported' => 0, 'skipped' => 0],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function getColumnMap(): array
    {
        return $this->columnMap;
    }
}
