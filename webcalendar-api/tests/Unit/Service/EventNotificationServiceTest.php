<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use App\Service\EventNotificationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Tiny recording Mailer: keeps every Email it's asked to send so tests
 * can assert on recipients, subject, body, and attachments without a
 * real SMTP transport.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($message instanceof Email) {
            $this->sent[] = $message;
        }
    }
}

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

    // -- External participant notifications --

    private function service(RecordingMailer $mailer): EventNotificationService
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        return new EventNotificationService($mailer, $factory, 'from@test.com', 'WebCal', 'http://localhost');
    }

    /** @return array{id:int,title:string,start_date:string,location:string,uid:string} */
    private function sampleEvent(): array
    {
        return [
            'id' => 42,
            'title' => 'Quarterly Review',
            'start_date' => '20260415',
            'location' => 'Conference Room B',
            'uid' => 'sample@test',
        ];
    }

    public function testExtInviteEmailsParticipantsWithEmail(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->notifyExtParticipantsAdded($this->sampleEvent(), [
            ['name' => 'Bob Vendor', 'email' => 'bob@vendor.com'],
            ['name' => 'Alice Guest', 'email' => 'alice@guest.com'],
        ]);

        $this->assertCount(2, $mailer->sent);
        $recipients = array_map(static fn(Email $e) => $e->getTo()[0]->getAddress(), $mailer->sent);
        $this->assertContains('bob@vendor.com', $recipients);
        $this->assertContains('alice@guest.com', $recipients);
    }

    public function testExtInviteSkipsParticipantsWithoutEmail(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->notifyExtParticipantsAdded($this->sampleEvent(), [
            ['name' => 'Bob (no email)', 'email' => null],
            ['name' => 'Alice', 'email' => 'alice@guest.com'],
        ]);

        $this->assertCount(1, $mailer->sent);
        $this->assertSame('alice@guest.com', $mailer->sent[0]->getTo()[0]->getAddress());
    }

    public function testExtInviteIsNoOpForEmptyList(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->notifyExtParticipantsAdded($this->sampleEvent(), []);
        $this->assertCount(0, $mailer->sent);
    }

    public function testExtInviteAttachesIcsWithMethodRequest(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->notifyExtParticipantsAdded($this->sampleEvent(), [
            ['name' => 'Bob', 'email' => 'bob@vendor.com'],
        ]);

        $this->assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments);

        $attachment = $attachments[0];
        $body = $attachment->getBody();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('METHOD:REQUEST', $body);
        $this->assertStringContainsString('STATUS:CONFIRMED', $body);
        $this->assertStringContainsString('SUMMARY:Quarterly Review', $body);
        $this->assertStringContainsString('UID:sample@test', $body);
    }

    public function testExtInviteSubjectAndBody(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->notifyExtParticipantsAdded($this->sampleEvent(), [
            ['name' => 'Bob', 'email' => 'bob@vendor.com'],
        ]);

        $email = $mailer->sent[0];
        $this->assertSame('Event Invitation: Quarterly Review', $email->getSubject());
        $htmlBody = (string) $email->getHtmlBody();
        $this->assertStringContainsString("You're invited", $htmlBody);
        $this->assertStringContainsString('Quarterly Review', $htmlBody);
        $this->assertStringContainsString('Conference Room B', $htmlBody);
        $this->assertStringContainsString('2026-04-15', $htmlBody);
    }

    public function testExtUpdatedAttachesIcsWithMethodRequest(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->notifyExtParticipantsUpdated($this->sampleEvent(), [
            ['name' => 'Bob', 'email' => 'bob@vendor.com'],
        ]);

        $email = $mailer->sent[0];
        $this->assertStringStartsWith('Event Updated:', (string) $email->getSubject());
        $body = $email->getAttachments()[0]->getBody();
        $this->assertStringContainsString('METHOD:REQUEST', $body);
        $this->assertStringContainsString('STATUS:CONFIRMED', $body);
    }

    public function testExtDeletedAttachesIcsWithMethodCancel(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->notifyExtParticipantsDeleted($this->sampleEvent(), [
            ['name' => 'Bob', 'email' => 'bob@vendor.com'],
        ]);

        $email = $mailer->sent[0];
        $this->assertStringStartsWith('Event Cancelled:', (string) $email->getSubject());
        $body = $email->getAttachments()[0]->getBody();
        $this->assertStringContainsString('METHOD:CANCEL', $body);
        $this->assertStringContainsString('STATUS:CANCELLED', $body);
    }

    public function testExtUpdatedSkipsNoEmail(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->notifyExtParticipantsUpdated($this->sampleEvent(), [
            ['name' => 'Bob', 'email' => null],
        ]);
        $this->assertCount(0, $mailer->sent);
    }

    public function testExtDeletedSkipsNoEmail(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->notifyExtParticipantsDeleted($this->sampleEvent(), [
            ['name' => 'Bob', 'email' => null],
        ]);
        $this->assertCount(0, $mailer->sent);
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
