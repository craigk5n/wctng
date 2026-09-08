<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Sends daily agenda emails to opted-in users.
 *
 * Tracks sent agendas in a `daily_agenda_sent` table to avoid duplicates.
 * Designed to be called via cron every hour (checks user's preferred send time).
 */
final class DailyAgendaService
{
    private LoggerInterface $logger;
    private ClockInterface $clock;

    public function __construct(
        private readonly TenantAwarePdoProvider $pdoProvider,
        private readonly UserRepositoryInterface $userRepository,
        private readonly EventService $eventService,
        private readonly ConfigService $configService,
        private readonly EmailSender $emailService,
        private readonly string $baseUrl,
        ?LoggerInterface $logger = null,
        #[\SensitiveParameter]
        private readonly string $appSecret = '',
        ?ClockInterface $clock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Sends daily agenda emails. Returns the count of agendas sent.
     */
    public function sendAgendas(): int
    {
        if (!$this->isEnabled()) {
            $this->logger->info('Daily agenda emails disabled globally');
            return 0;
        }

        $pdo = $this->pdoProvider->get();
        $this->ensureTrackingTable($pdo);

        $now = $this->clock->now();
        $today = $now->format('Y-m-d');
        $currentHour = (int) $now->format('G');
        $sent = 0;

        try {
            $users = $this->userRepository->findAll();
        } catch (\Throwable) {
            return 0;
        }

        foreach ($users as $user) {
            try {
                $prefs = $this->getUserAgendaPrefs($user->login());

                // Skip if agenda not enabled
                if (!$prefs['enabled']) {
                    continue;
                }

                // Only send at the user's preferred hour
                if ($prefs['hour'] !== $currentHour) {
                    continue;
                }

                // Skip if already sent today
                if ($this->isAgendaSent($pdo, $user->login(), $today)) {
                    continue;
                }

                // Get today's events
                $dayStart = new \DateTimeImmutable("{$today} 00:00:00");
                $dayEnd = new \DateTimeImmutable("{$today} 23:59:59");
                $range = new DateRange($dayStart, $dayEnd);
                $events = $this->eventService->getEventsInDateRange($range, $user);
                $dayEvents = $events->all();

                // Sort by start time
                usort($dayEvents, static fn($a, $b) => $a->start() <=> $b->start());

                // Skip empty days if user prefers
                if (\count($dayEvents) === 0 && $prefs['skip_empty']) {
                    continue;
                }

                // Render and send
                $html = $this->renderAgendaEmail($dayEvents, $today, $user->fullName(), $user->login());

                $this->emailService->send(
                    $user->email(),
                    'Daily Agenda — ' . (new \DateTimeImmutable($today))->format('l, F j, Y'),
                    $html,
                );

                $this->markAgendaSent($pdo, $user->login(), $today);
                $sent++;

                $this->logger->info('Daily agenda sent', [
                    'user' => $user->login(),
                    'events' => \count($dayEvents),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Daily agenda failed', [
                    'user' => $user->login(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Check if the admin has enabled daily agenda globally.
     */
    public function isEnabled(): bool
    {
        $value = $this->configService->getSetting('ENABLE_DAILY_AGENDA');
        // Default to N if not set (opt-in feature)
        return $value === 'Y';
    }

    /**
     * @return array{enabled: bool, hour: int, skip_empty: bool}
     */
    private function getUserAgendaPrefs(string $login): array
    {
        $enabled = false;
        $hour = 6; // Default: 06:00
        $skipEmpty = true;

        try {
            $prefs = $this->userRepository->getPreferences($login);
            foreach ($prefs as $pref) {
                if ($pref->key() === 'daily_agenda_enabled' && $pref->value() === 'Y') {
                    $enabled = true;
                }
                if ($pref->key() === 'daily_agenda_time') {
                    // Parse HH:MM format — take the hour part
                    $hour = (int) explode(':', $pref->value())[0];
                }
                if ($pref->key() === 'daily_agenda_skip_empty' && $pref->value() === 'N') {
                    $skipEmpty = false;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('factory->getUserRepository() failed', ['exception' => $e->getMessage()]);
        }

        return ['enabled' => $enabled, 'hour' => $hour, 'skip_empty' => $skipEmpty];
    }

    private function isAgendaSent(\PDO $pdo, string $login, string $date): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM daily_agenda_sent WHERE user_login = :login AND agenda_date = :date');
        $stmt->execute(['login' => $login, 'date' => $date]);
        return $stmt->fetch() !== false;
    }

    private function markAgendaSent(\PDO $pdo, string $login, string $date): void
    {
        $pdo->prepare('INSERT INTO daily_agenda_sent (user_login, agenda_date, sent_at) VALUES (:login, :date, :now)')
            ->execute(['login' => $login, 'date' => $date, 'now' => time()]);
    }

    private function ensureTrackingTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS daily_agenda_sent (
                user_login VARCHAR(60) NOT NULL,
                agenda_date VARCHAR(10) NOT NULL,
                sent_at INTEGER NOT NULL,
                PRIMARY KEY (user_login, agenda_date)
            )',
        );
    }

    /**
     * @param list<\WebCalendar\Core\Domain\Entity\Event> $events
     */
    private function renderAgendaEmail(array $events, string $date, string $displayName, string $login): string
    {
        $dateFormatted = (new \DateTimeImmutable($date))->format('l, F j, Y');
        $safeDisplayName = htmlspecialchars($displayName, \ENT_QUOTES, 'UTF-8');
        $unsubFooter = $this->renderUnsubscribeFooter($login);

        if (\count($events) === 0) {
            return <<<HTML
                <h2>{$safeDisplayName}'s Agenda — {$dateFormatted}</h2>
                <p>No events scheduled for today.</p>
                <p><a href="{$this->baseUrl}">Open Calendar</a></p>
                {$unsubFooter}
                HTML;
        }

        $eventListHtml = '';
        foreach ($events as $event) {
            $name = htmlspecialchars($event->name(), \ENT_QUOTES, 'UTF-8');
            $time = $event->isAllDay() ? 'All day' : $event->start()->format('g:i A');
            $loc = htmlspecialchars($event->location(), \ENT_QUOTES, 'UTF-8');
            $locHtml = $loc !== '' ? " — {$loc}" : '';
            $eventListHtml .= "<li><strong>{$time}</strong>: {$name}{$locHtml}</li>\n";
        }

        $count = \count($events);
        $countLabel = $count === 1 ? '1 event' : "{$count} events";

        return <<<HTML
            <h2>{$safeDisplayName}'s Agenda — {$dateFormatted}</h2>
            <p>{$countLabel} today:</p>
            <ul>
            {$eventListHtml}
            </ul>
            <p><a href="{$this->baseUrl}">Open Calendar</a></p>
            {$unsubFooter}
            HTML;
    }

    private function renderUnsubscribeFooter(string $login): string
    {
        if ($this->appSecret === '') {
            return '';
        }

        $token = \App\Controller\Api\UnsubscribeController::generateToken($login, $this->appSecret);
        $url = "{$this->baseUrl}/api/v2/unsubscribe/{$token}";

        return '<hr style="margin-top:1.5rem;border:none;border-top:1px solid #eee">'
            . '<p style="font-size:0.75rem;color:#999;margin-top:0.5rem">'
            . "<a href=\"{$url}\" style=\"color:#999\">Unsubscribe</a> from all WebCalendar email notifications."
            . '</p>';
    }
}
