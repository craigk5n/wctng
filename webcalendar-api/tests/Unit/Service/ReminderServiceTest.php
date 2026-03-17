<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use App\Service\EmailService;
use App\Service\ReminderService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

final class ReminderServiceTest extends TestCase
{
    public function testSendRemindersReturnsZeroWithNoUsers(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $factory = new CoreServiceFactory($pdo, 'test');
        $mailer = $this->createMock(MailerInterface::class);
        $emailService = new EmailService($mailer, 'from@test.com', 'WebCal');

        $service = new ReminderService($factory, $emailService, 'http://localhost');
        $count = $service->sendReminders();

        $this->assertSame(0, $count);
    }

    public function testTrackingTableCreatedAutomatically(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $factory = new CoreServiceFactory($pdo, 'test');
        $mailer = $this->createMock(MailerInterface::class);
        $emailService = new EmailService($mailer, 'from@test.com', 'WebCal');

        $service = new ReminderService($factory, $emailService, 'http://localhost');
        $service->sendReminders();

        // Table should exist now
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='reminder_sent'");
        $this->assertNotFalse($stmt);
        $this->assertNotFalse($stmt->fetch());
    }

    public function testDuplicateReminderNotSent(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create tracking table and insert a record
        $pdo->exec('CREATE TABLE reminder_sent (event_id INTEGER, user_login VARCHAR(60), sent_at INTEGER, PRIMARY KEY(event_id, user_login))');
        $pdo->exec("INSERT INTO reminder_sent VALUES (1, 'alice', " . time() . ')');

        // Verify duplicate check works via reflection
        $factory = new CoreServiceFactory($pdo, 'test');
        $mailer = $this->createMock(MailerInterface::class);
        $emailService = new EmailService($mailer, 'from@test.com', 'WebCal');

        $service = new ReminderService($factory, $emailService, 'http://localhost');
        $method = new \ReflectionMethod($service, 'isReminderSent');
        $this->assertTrue($method->invoke($service, $pdo, 1, 'alice'));
        $this->assertFalse($method->invoke($service, $pdo, 2, 'alice'));
    }

    public function testRenderReminderEmailContainsTitle(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        $mailer = $this->createMock(MailerInterface::class);
        $emailService = new EmailService($mailer, 'from@test.com', 'WebCal');

        $service = new ReminderService($factory, $emailService, 'http://localhost');
        $method = new \ReflectionMethod($service, 'renderReminderEmail');
        $html = $method->invoke($service, 'Team Standup', '2026-03-17', '09:00', 'Room A');

        $this->assertStringContainsString('Team Standup', $html);
        $this->assertStringContainsString('2026-03-17', $html);
        $this->assertStringContainsString('09:00', $html);
        $this->assertStringContainsString('Room A', $html);
    }
}
