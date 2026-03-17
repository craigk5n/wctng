<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\EmailService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class EmailServiceTest extends TestCase
{
    public function testSendCallsMailer(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send');

        $service = new EmailService($mailer, 'from@test.com', 'WebCal');
        $service->send('to@test.com', 'Subject', '<p>Body</p>');
    }

    public function testSendTestEmailReturnsTrue(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send');

        $service = new EmailService($mailer, 'from@test.com', 'WebCal');
        $this->assertTrue($service->sendTestEmail('to@test.com'));
    }

    public function testSendTestEmailReturnsFalseOnError(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('Transport error'));

        $service = new EmailService($mailer, 'from@test.com', 'WebCal');
        $this->assertFalse($service->sendTestEmail('to@test.com'));
    }

    public function testGetConfigReturnsFromAddress(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $service = new EmailService($mailer, 'noreply@example.com', 'MyCalendar');

        $config = $service->getConfig();
        $this->assertSame('noreply@example.com', $config['from_address']);
        $this->assertSame('MyCalendar', $config['from_name']);
        $this->assertSame('configured', $config['transport']);
    }

    public function testSendIncludesTextBody(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->with(
            $this->callback(function (Email $email): bool {
                return $email->getTextBody() === 'Plain text';
            }),
        );

        $service = new EmailService($mailer, 'from@test.com', 'WebCal');
        $service->send('to@test.com', 'Subject', '<p>HTML</p>', 'Plain text');
    }
}
