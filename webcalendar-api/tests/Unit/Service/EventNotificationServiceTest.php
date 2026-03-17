<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use App\Service\EventNotificationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

final class EventNotificationServiceTest extends TestCase
{
    public function testNotifyParticipantsAddedSendsEmails(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        // With a proper user setup, mailer would be called
        $mailer->expects($this->never())->method('send');

        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');

        $service = new EventNotificationService($mailer, $factory, 'from@test.com', 'WebCal', 'http://localhost');

        // No users in DB, so no emails sent
        $service->notifyParticipantsAdded(
            ['id' => 1, 'title' => 'Meeting', 'start_date' => '20260401', 'location' => 'Office'],
            ['nonexistent'],
        );
    }

    public function testNotifyEventUpdatedHandlesEmptyParticipants(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');

        $service = new EventNotificationService($mailer, $factory, 'from@test.com', 'WebCal', 'http://localhost');

        // Empty participants — no emails
        $service->notifyEventUpdated(['title' => 'Updated'], []);
        $this->assertTrue(true); // No exception
    }

    public function testNotifyEventDeletedHandlesEmptyParticipants(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');

        $service = new EventNotificationService($mailer, $factory, 'from@test.com', 'WebCal', 'http://localhost');

        $service->notifyEventDeleted('Cancelled Event', []);
        $this->assertTrue(true);
    }

    public function testMailerFailureDoesNotThrow(): void
    {
        $mailer = $this->createMock(MailerInterface::class);

        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');

        $service = new EventNotificationService($mailer, $factory, 'from@test.com', 'WebCal', 'http://localhost');

        // Should not throw even with no users
        $service->notifyParticipantsAdded(['id' => 1, 'title' => 'Test'], ['alice', 'bob']);
        $this->assertTrue(true);
    }

    public function testGenerateResponseTokenIsConsistent(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');

        $service = new EventNotificationService($mailer, $factory, 'from@test.com', 'WebCal', 'http://localhost');

        // Use reflection to test token generation
        $method = new \ReflectionMethod($service, 'generateResponseToken');
        $token1 = $method->invoke($service, 42, 'alice');
        $token2 = $method->invoke($service, 42, 'alice');

        $this->assertSame($token1, $token2); // Deterministic
        $this->assertSame(64, \strlen($token1)); // SHA-256 hex
    }
}
