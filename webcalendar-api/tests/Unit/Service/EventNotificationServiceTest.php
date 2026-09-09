<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CoreServiceFactory;
use App\Service\EventNotificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

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

        $service = new EventNotificationService($mailer, $factory->getUserService(), 'from@test.com', 'WebCal', 'http://localhost');

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

        $service = new EventNotificationService($mailer, $factory->getUserService(), 'from@test.com', 'WebCal', 'http://localhost');

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

        $service = new EventNotificationService($mailer, $factory->getUserService(), 'from@test.com', 'WebCal', 'http://localhost');

        $service->notifyEventDeleted('Cancelled Event', []);
        $this->assertTrue(true);
    }

    public function testMailerFailureDoesNotThrow(): void
    {
        $mailer = $this->createMock(MailerInterface::class);

        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');

        $service = new EventNotificationService($mailer, $factory->getUserService(), 'from@test.com', 'WebCal', 'http://localhost');

        // Should not throw even with no users
        $service->notifyParticipantsAdded(['id' => 1, 'title' => 'Test'], ['alice', 'bob']);
        $this->assertTrue(true);
    }

    // -- External participant notifications --

    private function service(RecordingMailer $mailer): EventNotificationService
    {
        $pdo = new \PDO('sqlite::memory:');
        $factory = new CoreServiceFactory($pdo, 'test');
        return new EventNotificationService($mailer, $factory->getUserService(), 'from@test.com', 'WebCal', 'http://localhost');
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

        $service = new EventNotificationService($mailer, $factory->getUserService(), 'from@test.com', 'WebCal', 'http://localhost');

        // Use reflection to test token generation
        $method = new \ReflectionMethod($service, 'generateResponseToken');
        $token1 = $method->invoke($service, 42, 'alice');
        $token2 = $method->invoke($service, 42, 'alice');

        $this->assertSame($token1, $token2); // Deterministic
        $this->assertSame(64, \strlen($token1)); // SHA-256 hex
    }
    /**
     * The calendar attachment is what recipients' clients actually import, and
     * nothing asserted a single line of it: every field, separator and the
     * CANCELLED/CONFIRMED choice could be altered without a test noticing.
     */
    public function testIcsAttachmentCarriesTheEventDetails(): void
    {
        $mailer = new RecordingMailer();
        $this->service($mailer)->notifyExtParticipantsAdded($this->sampleEvent(), [
            ['name' => 'Bob', 'email' => 'bob@vendor.com'],
        ]);

        $ics = (string) $mailer->sent[0]->getAttachments()[0]->getBody();

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith('END:VCALENDAR', $ics);
        self::assertStringContainsString("VERSION:2.0\r\n", $ics);
        self::assertStringContainsString("METHOD:REQUEST\r\n", $ics);
        self::assertStringContainsString("PRODID:-//WebCalendar//WCTNG//EN\r\n", $ics);
        self::assertStringContainsString("BEGIN:VEVENT\r\n", $ics);
        self::assertStringContainsString("UID:sample@test\r\n", $ics);
        self::assertStringContainsString("SUMMARY:Quarterly Review\r\n", $ics);
        self::assertStringContainsString("DTSTART:20260415\r\n", $ics);
        self::assertStringContainsString("STATUS:CONFIRMED\r\n", $ics);
        self::assertStringContainsString("END:VEVENT\r\n", $ics);
    }

    public function testCancellationIcsMarksTheEventCancelled(): void
    {
        $mailer = new RecordingMailer();
        $this->service($mailer)->notifyExtParticipantsDeleted($this->sampleEvent(), [
            ['name' => 'Bob', 'email' => 'bob@vendor.com'],
        ]);

        $ics = (string) $mailer->sent[0]->getAttachments()[0]->getBody();

        self::assertStringContainsString("METHOD:CANCEL\r\n", $ics);
        self::assertStringContainsString("STATUS:CANCELLED\r\n", $ics);
    }

    public function testIcsFallsBackToASyntheticUidWhenTheEventHasNone(): void
    {
        $event = $this->sampleEvent();
        unset($event['uid']);

        $mailer = new RecordingMailer();
        $this->service($mailer)->notifyExtParticipantsAdded($event, [
            ['name' => 'Bob', 'email' => 'bob@vendor.com'],
        ]);

        $ics = (string) $mailer->sent[0]->getAttachments()[0]->getBody();

        self::assertStringContainsString("UID:wctng-42@webcalendar\r\n", $ics);
    }

    public function testUpdateEmailNamesTheEventTheDateAndTheLocation(): void
    {
        $mailer = new RecordingMailer();
        $this->service($mailer)->notifyExtParticipantsUpdated($this->sampleEvent(), [
            ['name' => 'Bob', 'email' => 'bob@vendor.com'],
        ]);

        $email = $mailer->sent[0];
        $body = (string) $email->getHtmlBody();

        self::assertSame('Event Updated: Quarterly Review', $email->getSubject());
        self::assertStringContainsString('<h2>Event Updated: Quarterly Review</h2>', $body);
        self::assertStringContainsString('has been updated', $body);
        self::assertStringContainsString('2026-04-15', $body);
        self::assertStringContainsString('Conference Room B', $body);
    }

    public function testUpdateEmailOmitsTheLocationBlockWhenThereIsNoLocation(): void
    {
        $event = $this->sampleEvent();
        $event['location'] = '';

        $mailer = new RecordingMailer();
        $this->service($mailer)->notifyExtParticipantsUpdated($event, [
            ['name' => 'Bob', 'email' => 'bob@vendor.com'],
        ]);

        self::assertStringNotContainsString('Location:', (string) $mailer->sent[0]->getHtmlBody());
    }

    public function testAnEventWithNoTitleFallsBackToUntitled(): void
    {
        $event = $this->sampleEvent();
        unset($event['title']);

        $mailer = new RecordingMailer();
        $this->service($mailer)->notifyExtParticipantsUpdated($event, [
            ['name' => 'Bob', 'email' => 'bob@vendor.com'],
        ]);

        self::assertSame('Event Updated: Untitled Event', $mailer->sent[0]->getSubject());
    }

    public function testEveryExternalParticipantWithAnEmailIsSent(): void
    {
        // `continue` rather than `break`: one participant without an address
        // must not stop the ones after them being told.
        $mailer = new RecordingMailer();
        $this->service($mailer)->notifyExtParticipantsAdded($this->sampleEvent(), [
            ['name' => 'Bob', 'email' => 'bob@vendor.com'],
            ['name' => 'NoAddress', 'email' => ''],
            ['name' => 'Carol', 'email' => 'carol@vendor.com'],
        ]);

        $recipients = array_map(
            static fn(Email $e): string => $e->getTo()[0]->getAddress(),
            $mailer->sent,
        );

        self::assertSame(['bob@vendor.com', 'carol@vendor.com'], $recipients);
    }
    /**
     * A service whose user lookups actually resolve.
     *
     * The other helper hands CoreServiceFactory a schema-less database, so
     * getUserByLogin() never returns anyone and every internal-participant
     * path bails before composing anything -- which is why the existing
     * invitation test asserts that no mail is sent and notes that "with a
     * proper user setup, mailer would be called".
     */
    private function serviceWithUser(RecordingMailer $mailer, string $login, string $email): EventNotificationService
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(
            __DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql'
        );

        if ($schema === false) {
            self::fail('cannot read the webcalendar-core sqlite schema');
        }

        foreach (preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?: [] as $statement) {
            $statement = trim($statement);

            if ($statement !== '') {
                try {
                    $pdo->exec($statement);
                } catch (\PDOException) {
                    // Not every statement applies to this SQLite build.
                }
            }
        }

        $factory = new CoreServiceFactory($pdo, 'test');
        $factory->getUserRepository()->save(new User($login, 'Alice', 'Smith', $email, false, true));

        return new EventNotificationService(
            $mailer,
            $factory->getUserService(),
            'from@test.com',
            'WebCal',
            'https://cal.example.com',
        );
    }

    public function testInvitationEmailCarriesAcceptAndDeclineLinks(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUser($mailer, 'alice', 'alice@example.com');

        $service->notifyParticipantsAdded($this->sampleEvent(), ['alice']);

        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        $body = (string) $email->getHtmlBody();

        self::assertSame('alice@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('Event Invitation: Quarterly Review', $email->getSubject());
        self::assertStringContainsString("You're invited: Quarterly Review", $body);
        self::assertStringContainsString('2026-04-15', $body);
        self::assertStringContainsString('Conference Room B', $body);

        // Both actions, on the configured base URL, for this event, each
        // carrying the same signed token.
        self::assertMatchesRegularExpression(
            '#https://cal\.example\.com/api/v2/events/42/respond\?action=accept&token=[a-f0-9]+#',
            $body,
        );
        self::assertMatchesRegularExpression(
            '#https://cal\.example\.com/api/v2/events/42/respond\?action=decline&token=[a-f0-9]+#',
            $body,
        );
        self::assertStringContainsString('>Accept</a>', $body);
        self::assertStringContainsString('>Decline</a>', $body);
    }

    public function testInvitationAttachesTheCalendarInvite(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUser($mailer, 'alice', 'alice@example.com');

        $service->notifyParticipantsAdded($this->sampleEvent(), ['alice']);

        $ics = (string) $mailer->sent[0]->getAttachments()[0]->getBody();
        self::assertStringContainsString("METHOD:REQUEST\r\n", $ics);
        self::assertStringContainsString("SUMMARY:Quarterly Review\r\n", $ics);
    }

    public function testInvitationOmitsTheLocationBlockWhenThereIsNone(): void
    {
        $event = $this->sampleEvent();
        $event['location'] = '';

        $mailer = new RecordingMailer();
        $this->serviceWithUser($mailer, 'alice', 'alice@example.com')
            ->notifyParticipantsAdded($event, ['alice']);

        self::assertStringNotContainsString('Location:', (string) $mailer->sent[0]->getHtmlBody());
    }

    public function testUpdateAndCancellationEmailsReachTheParticipant(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUser($mailer, 'alice', 'alice@example.com');

        // These two take participant rows, not bare logins.
        $participants = [['login' => 'alice', 'status' => 'A']];
        $service->notifyEventUpdated($this->sampleEvent(), $participants);
        $service->notifyEventDeleted('Quarterly Review', $participants);

        self::assertCount(2, $mailer->sent);
        self::assertSame('Event Updated: Quarterly Review', $mailer->sent[0]->getSubject());
        self::assertSame('Event Cancelled: Quarterly Review', $mailer->sent[1]->getSubject());
        self::assertStringContainsString('Quarterly Review', (string) $mailer->sent[1]->getHtmlBody());
    }

    // ------------------------------------------------ a helper for two users

    /** Builds a service whose directory holds each of the given login => email. */
    private function serviceWithUsers(RecordingMailer $mailer, array $users): EventNotificationService
    {
        return $this->serviceAndFactory($mailer, $users)[0];
    }

    /**
     * @return array{EventNotificationService, CoreServiceFactory}
     */
    private function serviceAndFactory(RecordingMailer $mailer, array $users): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(
            __DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql'
        );
        self::assertIsString($schema);

        foreach (preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?: [] as $statement) {
            $statement = trim($statement);

            if ($statement !== '') {
                try {
                    $pdo->exec($statement);
                } catch (\PDOException) {
                    // Not every statement applies to this SQLite build.
                }
            }
        }

        $factory = new CoreServiceFactory($pdo, 'test');

        foreach ($users as $login => $email) {
            $factory->getUserRepository()->save(new User((string) $login, ucfirst((string) $login), 'Smith', (string) $email, false, true));
        }

        return [
            new EventNotificationService(
                $mailer,
                $factory->getUserService(),
                'from@test.com',
                'WebCal',
                'https://cal.example.com',
            ),
            $factory,
        ];
    }

    // ------------------------------------- one bad recipient stops nobody else

    public function testAParticipantWhoIsNotInTheDirectoryDoesNotStopTheRest(): void
    {
        // `continue`, not `break`: a stale participant row is ordinary, and if
        // it ended the loop everyone listed after it would silently never be
        // told. Also the only reachable half of the guard above it -- the
        // other half tests for an empty address, which the User entity
        // rejects at construction, so a user with one cannot be loaded.
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, ['bob' => 'bob@example.com']);

        $service->notifyParticipantsAdded($this->sampleEvent(), ['ghost', 'bob']);

        self::assertCount(1, $mailer->sent);
        self::assertSame('bob@example.com', $mailer->sent[0]->getTo()[0]->getAddress());
    }

    /** @return iterable<string, array{string}> */
    public static function participantNotifications(): iterable
    {
        yield 'updated' => ['notifyEventUpdated'];
        yield 'deleted' => ['notifyEventDeleted'];
    }

    #[DataProvider('participantNotifications')]
    public function testTheSameIsTrueForUpdatesAndCancellations(string $method): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, ['bob' => 'bob@example.com']);
        $participants = [['login' => 'ghost', 'status' => 'A'], ['login' => 'bob', 'status' => 'A']];

        $method === 'notifyEventDeleted'
            ? $service->notifyEventDeleted('Quarterly Review', $participants)
            : $service->notifyEventUpdated($this->sampleEvent(), $participants);

        self::assertCount(1, $mailer->sent);
        self::assertSame('bob@example.com', $mailer->sent[0]->getTo()[0]->getAddress());
    }

    public function testAnExternalParticipantWithNoAddressDoesNotStopTheRest(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, []);

        $service->notifyExtParticipantsUpdated($this->sampleEvent(), [
            ['email' => '', 'name' => 'No Address'],
            ['email' => 'ext@example.com', 'name' => 'External'],
        ]);

        self::assertCount(1, $mailer->sent);
        self::assertSame('ext@example.com', $mailer->sent[0]->getTo()[0]->getAddress());
    }

    public function testAnExternalParticipantWithNoAddressDoesNotStopACancellationEither(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, []);

        $service->notifyExtParticipantsDeleted($this->sampleEvent(), [
            ['email' => '', 'name' => 'No Address'],
            ['email' => 'ext@example.com', 'name' => 'External'],
        ]);

        self::assertCount(1, $mailer->sent);
    }

    // --------------------------------------------- what the emails actually say

    public function testTheUpdateEmailToAParticipantNamesTheEventAndItsDate(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, ['bob' => 'bob@example.com']);

        $service->notifyEventUpdated($this->sampleEvent(), [['login' => 'bob', 'status' => 'A']]);

        $body = (string) $mailer->sent[0]->getHtmlBody();
        self::assertStringContainsString('<h2>Event Updated: Quarterly Review</h2>', $body);
        self::assertStringContainsString('The event on 2026-04-15 has been updated.', $body);
        self::assertStringContainsString('href="https://cal.example.com"', $body);
    }

    public function testTheCancellationEmailToAParticipantNamesTheEvent(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, ['bob' => 'bob@example.com']);

        $service->notifyEventDeleted('Quarterly Review', [['login' => 'bob', 'status' => 'A']]);

        self::assertSame(
            '<h2>Event Cancelled: Quarterly Review</h2>'
                . '<p>This event has been cancelled by the organizer.</p>',
            (string) $mailer->sent[0]->getHtmlBody(),
        );
    }

    public function testTheExternalUpdateEmailIsExactlyThis(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, []);

        $service->notifyExtParticipantsUpdated($this->sampleEvent(), [['email' => 'ext@example.com', 'name' => 'Ext']]);

        self::assertSame(
            '<h2>Event Updated: Quarterly Review</h2>'
                . '<p>The event on 2026-04-15 has been updated.</p>'
                . '<p><strong>Location:</strong> Conference Room B</p>',
            (string) $mailer->sent[0]->getHtmlBody(),
        );
    }

    public function testTheExternalUpdateEmailDropsTheLocationBlockWhenThereIsNone(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, []);

        $service->notifyExtParticipantsUpdated(
            ['id' => 42, 'title' => 'Quarterly Review', 'start_date' => '20260415', 'uid' => 'sample@test'],
            [['email' => 'ext@example.com', 'name' => 'Ext']],
        );

        self::assertSame(
            '<h2>Event Updated: Quarterly Review</h2>'
                . '<p>The event on 2026-04-15 has been updated.</p>',
            (string) $mailer->sent[0]->getHtmlBody(),
        );
    }

    public function testTheExternalCancellationEmailIsExactlyThis(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, []);

        $service->notifyExtParticipantsDeleted($this->sampleEvent(), [['email' => 'ext@example.com', 'name' => 'Ext']]);

        self::assertSame(
            '<h2>Event Cancelled: Quarterly Review</h2>'
                . '<p>This event has been cancelled by the organizer.</p>',
            (string) $mailer->sent[0]->getHtmlBody(),
        );
    }

    public function testADateThatIsNotEightDigitsIsLeftAlone(): void
    {
        // formatDate() only reshapes YYYYMMDD; anything else is passed
        // through rather than sliced into nonsense.
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, ['bob' => 'bob@example.com']);

        $service->notifyEventUpdated(
            ['id' => 42, 'title' => 'Odd Date', 'start_date' => 'soon'],
            [['login' => 'bob', 'status' => 'A']],
        );

        self::assertStringContainsString('The event on soon has been updated.', (string) $mailer->sent[0]->getHtmlBody());
    }

    public function testAnEventWithNoStartDateStillSends(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, ['bob' => 'bob@example.com']);

        $service->notifyEventUpdated(['id' => 42, 'title' => 'No Date'], [['login' => 'bob', 'status' => 'A']]);

        self::assertStringContainsString('<h2>Event Updated: No Date</h2>', (string) $mailer->sent[0]->getHtmlBody());
    }

    // ------------------------------------------------- the calendar attachment

    public function testTheAttachedInviteIsAWellFormedICalendarObject(): void
    {
        // Every line of this is what a mail client parses to offer "add to
        // calendar", and each was a separate concatenation nothing read.
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, []);

        $service->notifyExtParticipantsAdded($this->sampleEvent(), [['email' => 'ext@example.com', 'name' => 'Ext']]);

        $ics = (string) $mailer->sent[0]->getAttachments()[0]->getBody();

        self::assertSame([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'METHOD:REQUEST',
            'PRODID:-//WebCalendar//WCTNG//EN',
            'BEGIN:VEVENT',
            'UID:sample@test',
            'SUMMARY:Quarterly Review',
            'DTSTART:20260415',
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ], explode("\r\n", $ics));
    }

    public function testACancellationInviteDiffersOnlyInItsMethodAndStatus(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, []);

        $service->notifyExtParticipantsDeleted($this->sampleEvent(), [['email' => 'ext@example.com', 'name' => 'Ext']]);

        $lines = explode("\r\n", (string) $mailer->sent[0]->getAttachments()[0]->getBody());

        self::assertContains('METHOD:CANCEL', $lines);
        self::assertContains('STATUS:CANCELLED', $lines);
    }

    public function testAnEventWithNoUidGetsOneMadeFromItsId(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, []);

        $service->notifyExtParticipantsAdded(
            ['id' => 42, 'title' => 'No Uid', 'start_date' => '20260415'],
            [['email' => 'ext@example.com', 'name' => 'Ext']],
        );

        self::assertStringContainsString(
            'UID:wctng-42@webcalendar',
            (string) $mailer->sent[0]->getAttachments()[0]->getBody(),
        );
    }

    public function testAnEventWithNeitherUidNorIdStillGetsAUid(): void
    {
        // The `?? 0` fallback on the id is what keeps the UID well-formed
        // rather than 'wctng-@webcalendar'.
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, []);

        $service->notifyExtParticipantsAdded(
            ['title' => 'Nothing', 'start_date' => '20260415'],
            [['email' => 'ext@example.com', 'name' => 'Ext']],
        );

        self::assertStringContainsString(
            'UID:wctng-0@webcalendar',
            (string) $mailer->sent[0]->getAttachments()[0]->getBody(),
        );
    }

    // ------------------------------------------------------------- opting out

    /** @return iterable<string, array{string, string}> */
    public static function optOutPreferences(): iterable
    {
        yield 'invitations' => ['EMAIL_INVITE', 'notifyParticipantsAdded'];
        yield 'updates' => ['EMAIL_UPDATE', 'notifyEventUpdated'];
    }

    public function testAParticipantWhoOptedOutOfUpdatesIsNotEmailed(): void
    {
        $mailer = new RecordingMailer();
        [$service, $factory] = $this->serviceAndFactory($mailer, ['bob' => 'bob@example.com']);
        $factory->getUserRepository()->savePreference('bob', new UserPreference('EMAIL_UPDATE', 'N'));

        $service->notifyEventUpdated($this->sampleEvent(), [['login' => 'bob', 'status' => 'A']]);

        self::assertSame([], $mailer->sent);
    }

    public function testAnOptOutIsFoundWhereverItSitsInThePreferenceList(): void
    {
        // The loop has to keep looking: `break` in place of the iteration
        // would only ever see the first preference, so an opt-out saved after
        // anything else would be ignored and the mail sent anyway.
        $mailer = new RecordingMailer();
        [$service, $factory] = $this->serviceAndFactory($mailer, ['bob' => 'bob@example.com']);
        $factory->getUserRepository()->savePreference('bob', new UserPreference('SOMETHING_ELSE', 'Y'));
        $factory->getUserRepository()->savePreference('bob', new UserPreference('EMAIL_UPDATE', 'N'));

        $service->notifyEventUpdated($this->sampleEvent(), [['login' => 'bob', 'status' => 'A']]);

        self::assertSame([], $mailer->sent);
    }

    public function testAPreferenceSetToAnythingButNoDoesNotOptOut(): void
    {
        $mailer = new RecordingMailer();
        [$service, $factory] = $this->serviceAndFactory($mailer, ['bob' => 'bob@example.com']);
        $factory->getUserRepository()->savePreference('bob', new UserPreference('EMAIL_UPDATE', 'Y'));

        $service->notifyEventUpdated($this->sampleEvent(), [['login' => 'bob', 'status' => 'A']]);

        self::assertCount(1, $mailer->sent);
    }

    public function testOptingOutOfOneKindOfMailDoesNotSilenceAnother(): void
    {
        $mailer = new RecordingMailer();
        [$service, $factory] = $this->serviceAndFactory($mailer, ['bob' => 'bob@example.com']);
        $factory->getUserRepository()->savePreference('bob', new UserPreference('EMAIL_INVITE', 'N'));

        $service->notifyEventUpdated($this->sampleEvent(), [['login' => 'bob', 'status' => 'A']]);

        self::assertCount(1, $mailer->sent, 'an invite opt-out is not an update opt-out');
    }

    // ------------------------------------------------- the response token

    public function testAnEventWithNoIdIsTokenisedAsEventZero(): void
    {
        // The accept and decline links are signed over "{id}:{login}", and an
        // event arriving without an id falls back to 0. Any other fallback
        // would hand the recipient a link that does not verify.
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, ['bob' => 'bob@example.com']);

        $service->notifyParticipantsAdded(['id' => 0, 'title' => 'Zero', 'start_date' => '20260415'], ['bob']);
        $service->notifyParticipantsAdded(['title' => 'Missing', 'start_date' => '20260415'], ['bob']);

        self::assertCount(2, $mailer->sent);
        self::assertSame(
            $this->tokenIn((string) $mailer->sent[0]->getHtmlBody()),
            $this->tokenIn((string) $mailer->sent[1]->getHtmlBody()),
        );
    }

    private function tokenIn(string $html): string
    {
        self::assertSame(1, preg_match('/token=([0-9a-f]+)/', $html, $m));

        return $m[1];
    }

    // ------------------------------------------------------- exact bodies

    public function testTheUpdateEmailToAParticipantIsExactlyThis(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, ['bob' => 'bob@example.com']);

        $service->notifyEventUpdated($this->sampleEvent(), [['login' => 'bob', 'status' => 'A']]);

        self::assertSame(
            '<h2>Event Updated: Quarterly Review</h2>'
                . '<p>The event on 2026-04-15 has been updated.</p>'
                . '<p><a href="https://cal.example.com">View in WebCalendar</a></p>',
            (string) $mailer->sent[0]->getHtmlBody(),
        );
    }

    public function testAnEmptyExternalListSendsNothingAtAll(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->serviceWithUsers($mailer, []);

        $service->notifyExtParticipantsAdded($this->sampleEvent(), []);

        self::assertSame([], $mailer->sent);
    }

    // ---------------------------------------------------------- the logger

    public function testAFailedSendIsReportedToTheInjectedLogger(): void
    {
        // Sending is best-effort -- a dead SMTP host must not fail the event
        // that triggered it -- so the log line is the only trace anything
        // went wrong. With the logger defaulted away it is silent.
        $logger = new CollectingLogger();
        $service = new EventNotificationService(
            new ThrowingMailer(),
            $this->serviceAndFactory(new RecordingMailer(), ['bob' => 'bob@example.com'])[1]->getUserService(),
            'from@test.com',
            'WebCal',
            'https://cal.example.com',
            $logger,
        );

        $service->notifyEventUpdated($this->sampleEvent(), [['login' => 'bob', 'status' => 'A']]);

        self::assertCount(1, $logger->warnings);
        self::assertSame('Failed to send notification email', $logger->warnings[0]['message']);
        self::assertSame('bob@example.com', $logger->warnings[0]['context']['to']);
        self::assertSame('Event Updated: Quarterly Review', $logger->warnings[0]['context']['subject']);
        self::assertSame('smtp is down', $logger->warnings[0]['context']['error']);
    }
}

/** A mailer that always fails, for the best-effort path. */
final class ThrowingMailer implements MailerInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        throw new \RuntimeException('smtp is down');
    }
}

/** Captures warnings so the logging decision can be asserted. */
final class CollectingLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ((string) $level === 'warning') {
            $this->warnings[] = ['message' => (string) $message, 'context' => $context];
        }
    }
}
