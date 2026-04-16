<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Narrow contract for sending transactional email — introduced so
 * `EmailService` could be marked `final` (PBP-S14) while letting tests
 * implement a capturing stub without extending a concrete class.
 */
interface EmailSender
{
    /**
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): void;
}
