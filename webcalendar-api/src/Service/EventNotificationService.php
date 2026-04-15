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

    /**
     * Invite external (email-only) participants who do not have user accounts.
     *
     * Unlike regular participants, external invitees have no opt-out preferences
     * and no accept/decline tokens — they're one-off guests. Each email includes
     * a standard METHOD:REQUEST .ics attachment so the recipient's calendar
     * client (Apple Mail, Outlook, Gmail) can tap-to-add the event.
     *
     * Participants without an email address are skipped — we have nowhere to
     * send the invitation.
     *
     * @param array<string, mixed> $eventData
     * @param list<array{name: string, email: ?string}> $extParticipants
     */
    public function notifyExtParticipantsAdded(array $eventData, array $extParticipants): void
    {
        if ($extParticipants === []) {
            return;
        }

        /** @var string $title */
        $title = $eventData['title'] ?? 'Untitled Event';
        /** @var string $startDate */
        $startDate = $eventData['start_date'] ?? '';
        /** @var string $location */
        $location = $eventData['location'] ?? '';

        $ics = $this->generateIcsAttachment($eventData, 'REQUEST');
        $html = $this->renderExtInvitationEmail($title, $startDate, $location);

        foreach ($extParticipants as $p) {
            $email = $p['email'] ?? null;
            if ($email === null || $email === '') {
                continue;
            }
            $this->sendEmail($email, "Event Invitation: {$title}", $html, $ics);
        }
    }

    /**
     * Notify external participants that an event has been updated.
     *
     * Sends a METHOD:REQUEST .ics — RFC 5546 lets clients treat REQUEST on a
     * known UID as an update, keeping the calendar entry in sync.
     *
     * @param array<string, mixed> $eventData
     * @param list<array{name: string, email: ?string}> $extParticipants
     */
    public function notifyExtParticipantsUpdated(array $eventData, array $extParticipants): void
    {
        if ($extParticipants === []) {
            return;
        }

        /** @var string $title */
        $title = $eventData['title'] ?? 'Untitled Event';
        /** @var string $startDate */
        $startDate = $eventData['start_date'] ?? '';
        /** @var string $location */
        $location = $eventData['location'] ?? '';

        $ics = $this->generateIcsAttachment($eventData, 'REQUEST');
        $html = "<h2>Event Updated: {$title}</h2>"
            . '<p>The event on ' . $this->formatDate($startDate) . ' has been updated.</p>'
            . ($location !== '' ? "<p><strong>Location:</strong> {$location}</p>" : '');

        foreach ($extParticipants as $p) {
            $email = $p['email'] ?? null;
            if ($email === null || $email === '') {
                continue;
            }
            $this->sendEmail($email, "Event Updated: {$title}", $html, $ics);
        }
    }

    /**
     * Notify external participants that an event has been cancelled.
     *
     * Sends a METHOD:CANCEL .ics so the recipient's calendar client can
     * automatically remove the entry per RFC 5546.
     *
     * @param array<string, mixed> $eventData
     * @param list<array{name: string, email: ?string}> $extParticipants
     */
    public function notifyExtParticipantsDeleted(array $eventData, array $extParticipants): void
    {
        if ($extParticipants === []) {
            return;
        }

        /** @var string $title */
        $title = $eventData['title'] ?? 'Untitled Event';

        $ics = $this->generateIcsAttachment($eventData, 'CANCEL');
        $html = "<h2>Event Cancelled: {$title}</h2>"
            . '<p>This event has been cancelled by the organizer.</p>';

        foreach ($extParticipants as $p) {
            $email = $p['email'] ?? null;
            if ($email === null || $email === '') {
                continue;
            }
            $this->sendEmail($email, "Event Cancelled: {$title}", $html, $ics);
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
    private function generateIcsAttachment(array $eventData, string $method = 'REQUEST'): string
    {
        /** @var string $title */
        $title = $eventData['title'] ?? '';
        /** @var string $startDate */
        $startDate = $eventData['start_date'] ?? '';
        /** @var string|int $eid */
        $eid = $eventData['id'] ?? 0;
        /** @var string $uid */
        $uid = \is_string($eventData['uid'] ?? null) ? $eventData['uid'] : 'wctng-' . $eid . '@webcalendar';

        $status = $method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED';

        return "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "METHOD:{$method}\r\n"
            . "PRODID:-//WebCalendar//WCTNG//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\n"
            . "SUMMARY:{$title}\r\n"
            . "DTSTART:{$startDate}\r\n"
            . "STATUS:{$status}\r\n"
            . "END:VEVENT\r\n"
            . 'END:VCALENDAR';
    }

    private function renderExtInvitationEmail(string $title, string $date, string $location): string
    {
        $locationHtml = $location !== '' ? "<p><strong>Location:</strong> {$location}</p>" : '';

        return <<<HTML
            <h2>You're invited: {$title}</h2>
            <p><strong>When:</strong> {$this->formatDate($date)}</p>
            {$locationHtml}
            <p>An event invitation is attached. Open it with your calendar app to add this event.</p>
            HTML;
    }

    private function formatDate(string $yyyymmdd): string
    {
        if (\strlen($yyyymmdd) !== 8) {
            return $yyyymmdd;
        }

        return substr($yyyymmdd, 0, 4) . '-' . substr($yyyymmdd, 4, 2) . '-' . substr($yyyymmdd, 6, 2);
    }
}
