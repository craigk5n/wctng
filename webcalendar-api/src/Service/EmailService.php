<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Service for sending emails via Symfony Mailer.
 *
 * Wraps MailerInterface with application-specific defaults.
 */
final class EmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    /**
     * Sends an email.
     *
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): void
    {
        $email = (new Email())
            ->from("{$this->fromName} <{$this->fromAddress}>")
            ->to($to)
            ->subject($subject)
            ->html($htmlBody);

        if ($textBody !== null) {
            $email->text($textBody);
        }

        $this->mailer->send($email);
    }

    /**
     * Sends a test email to verify transport configuration.
     */
    public function sendTestEmail(string $to): bool
    {
        try {
            $this->send(
                $to,
                'WebCalendar Test Email',
                '<h2>Email Configuration Working</h2><p>This is a test email from WebCalendar.</p>',
                'WebCalendar Test Email - Email configuration is working.',
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getConfig(): array
    {
        return [
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
            'transport' => 'configured', // Don't expose DSN details
        ];
    }
}
