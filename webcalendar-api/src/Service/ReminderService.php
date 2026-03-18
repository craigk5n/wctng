<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Finds upcoming events and sends reminder emails.
 *
 * Tracks sent reminders in a `reminder_sent` table to avoid duplicates.
 * Designed to be called via cron every minute.
 */
final class ReminderService
{
    private const DEFAULT_REMINDER_MINUTES = 30;
    private LoggerInterface $logger;

    public function __construct(
        private readonly CoreServiceFactory $coreServiceFactory,
        private readonly EmailService $emailService,
        private readonly string $baseUrl,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Sends reminders for upcoming events. Returns the count of reminders sent.
     */
    public function sendReminders(): int
    {
        // Check admin feature flag
        if (!$this->isEnabled()) {
            $this->logger->info('Email reminders disabled globally');
            return 0;
        }

        $pdo = $this->coreServiceFactory->getPdo();
        $this->ensureTrackingTable($pdo);

        $now = new \DateTimeImmutable();
        $sent = 0;

        // Get all users with their reminder preferences
        try {
            $users = $this->coreServiceFactory->getUserRepository()->findAll();
        } catch (\Throwable) {
            return 0;
        }

        foreach ($users as $user) {
            $minutes = $this->getReminderMinutes($user->login());
            if ($minutes <= 0) {
                continue; // Reminders disabled for this user
            }

            $windowStart = $now;
            $windowEnd = $now->modify("+{$minutes} minutes");

            try {
                $range = new \WebCalendar\Core\Domain\ValueObject\DateRange($windowStart, $windowEnd);
                $events = $this->coreServiceFactory->getEventService()->getEventsInDateRange($range, $user);

                foreach ($events->all() as $event) {
                    $eventId = $event->id()->value();
                    $eventStart = $event->start();

                    // Check if event starts within the reminder window
                    if ($eventStart < $windowStart || $eventStart > $windowEnd) {
                        continue;
                    }

                    // Skip cancelled or rejected events
                    $status = $event->status();
                    if ($status === 'cancelled' || $status === 'rejected') {
                        continue;
                    }

                    // Skip events where this user's participant status is rejected
                    if ($this->isUserRejected($eventId, $user->login())) {
                        continue;
                    }

                    // Check if already sent
                    if ($this->isReminderSent($pdo, $eventId, $user->login())) {
                        continue;
                    }

                    // Send reminder
                    $timeStr = $event->isAllDay() ? 'All day' : $eventStart->format('H:i');
                    $dateStr = $eventStart->format('Y-m-d');
                    $html = $this->renderReminderEmail(
                        $event->name(),
                        $dateStr,
                        $timeStr,
                        $event->location(),
                    );

                    $this->emailService->send(
                        $user->email(),
                        'Reminder: ' . htmlspecialchars($event->name(), \ENT_QUOTES, 'UTF-8'),
                        $html,
                    );

                    $this->markReminderSent($pdo, $eventId, $user->login());
                    $sent++;

                    $this->logger->info('Reminder sent', ['event' => $eventId, 'user' => $user->login()]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Reminder processing failed', [
                    'user' => $user->login(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Check if the admin has enabled email reminders globally.
     */
    public function isEnabled(): bool
    {
        $value = $this->coreServiceFactory->getConfigService()->getSetting('ENABLE_EMAIL_REMINDERS');
        // Default to Y if not set
        return $value !== 'N';
    }

    private function getReminderMinutes(string $login): int
    {
        try {
            $user = $this->coreServiceFactory->getUserService()->getUserByLogin($login);
            if ($user === null) {
                return self::DEFAULT_REMINDER_MINUTES;
            }

            $prefs = $this->coreServiceFactory->getUserService()->getPreferences($login, $user);
            foreach ($prefs as $pref) {
                if ($pref->key() === 'REMINDER_MINUTES') {
                    $val = (int) $pref->value();
                    return $val >= 0 ? $val : 0; // 0 = disabled
                }
            }
        } catch (\Throwable) {
        }

        return self::DEFAULT_REMINDER_MINUTES;
    }

    private function isReminderSent(\PDO $pdo, int $eventId, string $login): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM reminder_sent WHERE event_id = :eid AND user_login = :login');
        $stmt->execute(['eid' => $eventId, 'login' => $login]);

        return $stmt->fetch() !== false;
    }

    private function markReminderSent(\PDO $pdo, int $eventId, string $login): void
    {
        $pdo->prepare('INSERT INTO reminder_sent (event_id, user_login, sent_at) VALUES (:eid, :login, :now)')
            ->execute(['eid' => $eventId, 'login' => $login, 'now' => time()]);
    }

    private function ensureTrackingTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reminder_sent (
                event_id INTEGER NOT NULL,
                user_login VARCHAR(60) NOT NULL,
                sent_at INTEGER NOT NULL,
                PRIMARY KEY (event_id, user_login)
            )',
        );
    }

    private function isUserRejected(int $eventId, string $login): bool
    {
        try {
            /** @var array<string, string> $participants */
            $participants = $this->coreServiceFactory->getEventRepository()
                ->getParticipantsWithStatus(new \WebCalendar\Core\Domain\ValueObject\EventId($eventId));

            $status = $participants[$login] ?? null;
            return $status === 'R'; // R = Rejected
        } catch (\Throwable) {
            return false;
        }
    }

    private function renderReminderEmail(string $title, string $date, string $time, string $location): string
    {
        $safeTitle = htmlspecialchars($title, \ENT_QUOTES, 'UTF-8');
        $safeLocation = htmlspecialchars($location, \ENT_QUOTES, 'UTF-8');
        $locationHtml = $safeLocation !== '' ? "<p><strong>Location:</strong> {$safeLocation}</p>" : '';

        return <<<HTML
        <h2>Upcoming: {$safeTitle}</h2>
        <p><strong>When:</strong> {$date} at {$time}</p>
        {$locationHtml}
        <p><a href="{$this->baseUrl}">View in WebCalendar</a></p>
        HTML;
    }
}
