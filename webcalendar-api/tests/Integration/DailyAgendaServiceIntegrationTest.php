<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\DailyAgendaService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class DailyAgendaServiceIntegrationTest extends IntegrationTestCase
{
    private DailyAgendaService $service;
    private StubEmailService $emailStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emailStub = new StubEmailService();

        // Enable feature globally
        $this->factory->getConfigService()->updateSetting('ENABLE_DAILY_AGENDA', 'Y');

        // Disable for admin to isolate tests
        $this->factory->getUserRepository()->savePreference(
            'admin',
            new UserPreference('daily_agenda_enabled', 'N'),
        );

        $this->service = new DailyAgendaService(
            $this->factory,
            $this->emailStub,
            'http://localhost:47180',
        );
    }

    private function enableAgendaForAlice(?int $hour = null): void
    {
        $this->factory->getUserRepository()->savePreference(
            'alice',
            new UserPreference('daily_agenda_enabled', 'Y'),
        );
        if ($hour !== null) {
            $this->factory->getUserRepository()->savePreference(
                'alice',
                new UserPreference('daily_agenda_time', sprintf('%02d:00', $hour)),
            );
        }
    }

    private function createTodayEvent(string $title, string $time = '10:00:00'): void
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $event = new Event(
            id: new EventId(0),
            uid: 'agenda-' . bin2hex(random_bytes(4)) . '@test',
            name: $title,
            description: '',
            location: 'Room 1',
            start: new \DateTimeImmutable("{$today} {$time}"),
            duration: 60,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
        );
        $this->factory->getEventService()->createEvent($event, $this->normalUser);
    }

    public function testSendsAgendaWhenEnabledAndHourMatches(): void
    {
        $currentHour = (int) (new \DateTimeImmutable())->format('G');
        $this->enableAgendaForAlice($currentHour);
        $this->createTodayEvent('Morning Standup');

        $sent = $this->service->sendAgendas();
        $this->assertSame(1, $sent);
        $this->assertCount(1, $this->emailStub->sent);
        $this->assertSame('alice@test.com', $this->emailStub->sent[0]['to']);
        $this->assertStringContainsString('Daily Agenda', $this->emailStub->sent[0]['subject']);
    }

    public function testDoesNotSendWhenDisabledGlobally(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_DAILY_AGENDA', 'N');

        $currentHour = (int) (new \DateTimeImmutable())->format('G');
        $this->enableAgendaForAlice($currentHour);
        $this->createTodayEvent('Test');

        // Re-create service with updated config
        $service = new DailyAgendaService($this->factory, $this->emailStub, 'http://localhost');
        $this->assertSame(0, $service->sendAgendas());
    }

    public function testDoesNotSendWhenUserNotOptedIn(): void
    {
        // alice has NOT enabled daily_agenda_enabled
        $this->createTodayEvent('Test');

        $this->assertSame(0, $this->service->sendAgendas());
    }

    public function testDoesNotSendAtWrongHour(): void
    {
        // Set to an hour that is NOT the current hour
        $currentHour = (int) (new \DateTimeImmutable())->format('G');
        $wrongHour = ($currentHour + 6) % 24;
        $this->enableAgendaForAlice($wrongHour);
        $this->createTodayEvent('Test');

        $this->assertSame(0, $this->service->sendAgendas());
    }

    public function testSkipsEmptyDaysByDefault(): void
    {
        $currentHour = (int) (new \DateTimeImmutable())->format('G');
        $this->enableAgendaForAlice($currentHour);
        // No events created

        $this->assertSame(0, $this->service->sendAgendas());
    }

    public function testSendsEmptyDayWhenSkipEmptyDisabled(): void
    {
        $currentHour = (int) (new \DateTimeImmutable())->format('G');
        $this->enableAgendaForAlice($currentHour);
        $this->factory->getUserRepository()->savePreference(
            'alice',
            new UserPreference('daily_agenda_skip_empty', 'N'),
        );
        // No events

        $this->assertSame(1, $this->service->sendAgendas());
        $this->assertCount(1, $this->emailStub->sent);
    }

    public function testDoesNotDuplicateAgendas(): void
    {
        $currentHour = (int) (new \DateTimeImmutable())->format('G');
        $this->enableAgendaForAlice($currentHour);
        $this->createTodayEvent('Standup');

        $this->service->sendAgendas();
        $this->assertCount(1, $this->emailStub->sent);

        // Run again — should not duplicate
        $this->service->sendAgendas();
        $this->assertCount(1, $this->emailStub->sent);
    }

    public function testIsEnabledDefaultsToFalse(): void
    {
        // Reset the config
        $this->factory->getConfigService()->updateSetting('ENABLE_DAILY_AGENDA', 'N');
        $service = new DailyAgendaService($this->factory, $this->emailStub, 'http://localhost');
        $this->assertFalse($service->isEnabled());
    }
}
