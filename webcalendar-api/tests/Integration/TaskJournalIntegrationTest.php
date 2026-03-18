<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class TaskJournalIntegrationTest extends IntegrationTestCase
{
    public function testTaskCrud(): void
    {
        $taskService = $this->factory->getTaskService();

        $task = new Task(
            id: new EventId(0),
            uid: 'task-test@test',
            name: 'Integration Task',
            description: 'Test task',
            location: '',
            start: new \DateTimeImmutable('2026-06-01'),
            duration: 0,
            createdBy: 'admin',
            type: EventType::TASK,
            access: AccessLevel::PUBLIC,
        );

        $taskService->createTask($task, $this->adminUser);

        $range = new DateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );
        $tasks = $taskService->getTasksInDateRange($range, 'admin');
        $names = array_map(fn ($t) => $t->name(), $tasks);
        $this->assertContains('Integration Task', $names);
    }

    public function testJournalCrud(): void
    {
        $journalService = $this->factory->getJournalService();

        $journal = new Journal(
            id: new EventId(0),
            uid: 'journal-test@test',
            name: 'Integration Journal',
            description: 'Journal entry text',
            location: '',
            start: new \DateTimeImmutable('2026-06-01'),
            duration: 0,
            createdBy: 'admin',
            type: EventType::JOURNAL,
            access: AccessLevel::PUBLIC,
        );

        $journalService->createJournal($journal, $this->adminUser);

        $range = new DateRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );
        $journals = $journalService->getJournalsInDateRange($range, 'admin');
        $names = array_map(fn ($j) => $j->name(), $journals);
        $this->assertContains('Integration Journal', $names);
    }
}
