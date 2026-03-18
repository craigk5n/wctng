<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\EmailService;

/**
 * Stub EmailService that captures sent emails instead of actually sending.
 */
class StubEmailService extends EmailService
{
    /** @var list<array{to: string, subject: string}> */
    public array $sent = [];

    public function __construct()
    {
        // Skip parent constructor (needs MailerInterface)
    }

    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject];
    }
}
