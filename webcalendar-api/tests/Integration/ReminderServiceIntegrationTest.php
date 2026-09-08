<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\ReminderService;
use App\Service\TenantAwarePdoProvider;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class ReminderServiceIntegrationTest extends IntegrationTestCase
{
    private ReminderService $service;
    private StubEmailService $emailStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emailStub = new StubEmailService();
        $this->service = new ReminderService(
            new TenantAwarePdoProvider($this->factory->getPdo()),
            $this->factory->getUserRepository(),
            $this->factory->getEventRepository(),
            $this->factory->getEventService(),
            $this->factory->getUserService(),
            $this->factory->getConfigService(),
            $this->emailStub,
            'http://localhost:47180',
        );

        // Disable reminders for admin user to isolate tests to alice
        $this->factory->getUserRepository()->savePreference(
            'admin',
            new UserPreference('REMINDER_MINUTES', '0'),
        );
    }

    private function createEventAt(\DateTimeImmutable $start, string $title = 'Test Event', ?string $status = null): int
    {
        $event = new Event(
            id: new EventId(0),
            uid: 'rem-' . bin2hex(random_bytes(4)) . '@test',
            name: $title,
            description: '',
            location: 'Room 1',
            start: $start,
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            status: $status,
        );
        $this->factory->getEventService()->createEvent($event, $this->normalUser);
        $created = $this->factory->getEventRepository()->findByUid($event->uid());
        return $created !== null ? $created->id()->value() : 0;
    }

    public function testSendsReminderForUpcomingEvent(): void
    {
        // Set reminder to 30 minutes (default)
        $start = new \DateTimeImmutable('+20 minutes');
        $this->createEventAt($start, 'Soon Event');

        $sent = $this->service->sendReminders();
        $this->assertSame(1, $sent);
        $this->assertCount(1, $this->emailStub->sent);
        $this->assertSame('alice@test.com', $this->emailStub->sent[0]['to']);
    }

    public function testDoesNotSendWhenDisabledGlobally(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_EMAIL_REMINDERS', 'N');

        $start = new \DateTimeImmutable('+20 minutes');
        $this->createEventAt($start);

        $sent = $this->service->sendReminders();
        $this->assertSame(0, $sent);
        $this->assertCount(0, $this->emailStub->sent);
    }

    public function testDoesNotSendWhenUserDisabled(): void
    {
        $this->factory->getUserRepository()->savePreference(
            'alice',
            new UserPreference('REMINDER_MINUTES', '0'),
        );

        $start = new \DateTimeImmutable('+20 minutes');
        $this->createEventAt($start);

        $sent = $this->service->sendReminders();
        $this->assertSame(0, $sent);
    }

    public function testSkipsCancelledEvents(): void
    {
        $start = new \DateTimeImmutable('+20 minutes');
        $this->createEventAt($start, 'Cancelled Meeting', 'cancelled');

        $sent = $this->service->sendReminders();
        $this->assertSame(0, $sent);
    }

    public function testSkipsRejectedEvents(): void
    {
        $start = new \DateTimeImmutable('+20 minutes');
        $this->createEventAt($start, 'Rejected Meeting', 'rejected');

        $sent = $this->service->sendReminders();
        $this->assertSame(0, $sent);
    }

    public function testDoesNotDuplicateReminders(): void
    {
        $start = new \DateTimeImmutable('+20 minutes');
        $this->createEventAt($start, 'Once Only');

        $this->service->sendReminders();
        $this->assertCount(1, $this->emailStub->sent);

        // Run again — should not send duplicate
        $this->service->sendReminders();
        $this->assertCount(1, $this->emailStub->sent);
    }

    public function testIsEnabledDefaultsToTrue(): void
    {
        $this->assertTrue($this->service->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenSetToN(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_EMAIL_REMINDERS', 'N');
        $this->assertFalse($this->service->isEnabled());
    }
}
