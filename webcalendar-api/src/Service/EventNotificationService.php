<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends email notifications for event lifecycle changes.
 *
 * Notifications are fire-and-forget — failures are logged but don't block the API.
 */
final class EventNotificationService
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly CoreServiceFactory $coreServiceFactory,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $baseUrl,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Notify participants when they are added to an event.
     *
     * @param array<string, mixed> $eventData
     * @param list<string> $participantLogins
     */
    public function notifyParticipantsAdded(array $eventData, array $participantLogins): void
    {
        /** @var string $title */
        $title = $eventData['title'] ?? 'Untitled Event';
        /** @var string $startDate */
        $startDate = $eventData['start_date'] ?? '';
        /** @var string $location */
        $location = $eventData['location'] ?? '';
        /** @var int $eventId */
        $eventId = $eventData['id'] ?? 0;

        foreach ($participantLogins as $login) {
            try {
                $user = $this->coreServiceFactory->getUserService()->getUserByLogin($login);
            } catch (\Throwable) {
                continue;
            }
            if ($user === null || $user->email() === '') {
                continue;
            }

            // Check if user has opted out
            if ($this->hasOptedOut($login, 'invitation')) {
                continue;
            }

            $token = $this->generateResponseToken($eventId, $login);
            $acceptUrl = "{$this->baseUrl}/api/v2/events/{$eventId}/respond?action=accept&token={$token}";
            $declineUrl = "{$this->baseUrl}/api/v2/events/{$eventId}/respond?action=decline&token={$token}";

            $html = $this->renderInvitationEmail($title, $startDate, $location, $acceptUrl, $declineUrl);
            $ics = $this->generateIcsAttachment($eventData);

            $this->sendEmail(
                $user->email(),
                "Event Invitation: {$title}",
                $html,
                $ics,
            );
        }
    }

    /**
     * Notify participants when an event is updated.
     *
     * @param array<string, mixed> $eventData
     * @param list<array{login: string, status: string}> $participants
     */
    public function notifyEventUpdated(array $eventData, array $participants): void
    {
        /** @var string $title */
        $title = $eventData['title'] ?? 'Untitled Event';
        /** @var string $startDate */
        $startDate = $eventData['start_date'] ?? '';

        foreach ($participants as $p) {
            try {
                $user = $this->coreServiceFactory->getUserService()->getUserByLogin($p['login']);
            } catch (\Throwable) {
                continue;
            }
            if ($user === null || $user->email() === '') {
                continue;
            }

            if ($this->hasOptedOut($p['login'], 'update')) {
                continue;
            }

            $html = "<h2>Event Updated: {$title}</h2>"
                . "<p>The event on {$this->formatDate($startDate)} has been updated.</p>"
                . "<p><a href=\"{$this->baseUrl}\">View in WebCalendar</a></p>";

            $this->sendEmail($user->email(), "Event Updated: {$title}", $html);
        }
    }

    /**
     * Notify participants when an event is deleted.
     *
     * @param list<array{login: string, status: string}> $participants
     */
    public function notifyEventDeleted(string $title, array $participants): void
    {
        foreach ($participants as $p) {
            try {
                $user = $this->coreServiceFactory->getUserService()->getUserByLogin($p['login']);
            } catch (\Throwable) {
                continue;
            }
            if ($user === null || $user->email() === '') {
                continue;
            }

            if ($this->hasOptedOut($p['login'], 'update')) {
                continue;
            }

            $html = "<h2>Event Cancelled: {$title}</h2>"
                . '<p>This event has been cancelled by the organizer.</p>';

            $this->sendEmail($user->email(), "Event Cancelled: {$title}", $html);
        }
    }

    private function sendEmail(string $to, string $subject, string $html, ?string $icsAttachment = null): void
    {
        try {
            $email = (new Email())
                ->from("{$this->fromName} <{$this->fromAddress}>")
                ->to($to)
                ->subject($subject)
                ->html($html);

            if ($icsAttachment !== null) {
                $email->attach($icsAttachment, 'event.ics', 'text/calendar');
            }

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to send notification email', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function hasOptedOut(string $login, string $type): bool
    {
        try {
            $prefs = $this->coreServiceFactory->getUserService()->getPreferences(
                $login,
                $this->coreServiceFactory->getUserService()->getUserByLogin($login) ?? new \WebCalendar\Core\Domain\Entity\User(
                    login: $login,
                    firstName: '',
                    lastName: '',
                    email: '',
                    isAdmin: false,
                    isEnabled: true,
                ),
            );

            foreach ($prefs as $pref) {
                if ($pref->key() === 'EMAIL_' . strtoupper($type) && $pref->value() === 'N') {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Default: not opted out
        }

        return false;
    }

    private function generateResponseToken(int $eventId, string $login): string
    {
        return hash_hmac('sha256', "{$eventId}:{$login}", $this->fromAddress);
    }

    private function renderInvitationEmail(string $title, string $date, string $location, string $acceptUrl, string $declineUrl): string
    {
        $locationHtml = $location !== '' ? "<p><strong>Location:</strong> {$location}</p>" : '';

        return <<<HTML
        <h2>You're invited: {$title}</h2>
        <p><strong>When:</strong> {$this->formatDate($date)}</p>
        {$locationHtml}
        <p>
            <a href="{$acceptUrl}" style="display:inline-block;padding:8px 16px;background:#22c55e;color:#fff;text-decoration:none;border-radius:4px">Accept</a>
            &nbsp;
            <a href="{$declineUrl}" style="display:inline-block;padding:8px 16px;background:#ef4444;color:#fff;text-decoration:none;border-radius:4px">Decline</a>
        </p>
        HTML;
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function generateIcsAttachment(array $eventData): string
    {
        /** @var string $title */
        $title = $eventData['title'] ?? '';
        /** @var string $startDate */
        $startDate = $eventData['start_date'] ?? '';
        /** @var string|int $eid */
        $eid = $eventData['id'] ?? 0;
        /** @var string $uid */
        $uid = \is_string($eventData['uid'] ?? null) ? $eventData['uid'] : 'wctng-' . $eid . '@webcalendar';

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\nSUMMARY:{$title}\r\nDTSTART:{$startDate}\r\nEND:VEVENT\r\nEND:VCALENDAR";
    }

    private function formatDate(string $yyyymmdd): string
    {
        if (\strlen($yyyymmdd) !== 8) {
            return $yyyymmdd;
        }

        return substr($yyyymmdd, 0, 4) . '-' . substr($yyyymmdd, 4, 2) . '-' . substr($yyyymmdd, 6, 2);
    }
}
