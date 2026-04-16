<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\EmailSender;

/**
 * Test-capture stand-in for {@see \App\Service\EmailService}. Implements
 * the narrow `EmailSender` contract instead of extending the concrete
 * (now final) class — see PBP-S14.
 */
final class StubEmailService implements EmailSender
{
    /** @var list<array{to: string, subject: string}> */
    public array $sent = [];

    #[\Override]
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject];
    }
}
