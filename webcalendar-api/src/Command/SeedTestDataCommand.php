<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\PasswordHasher;
use App\Service\CoreServiceFactory;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;

#[AsCommand(
    name: 'webcalendar:seed-test-data',
    description: 'Generate realistic large-scale test data for performance testing.',
)]
final class SeedTestDataCommand extends Command
{
    // Realistic first/last names for generating users
    private const FIRST_NAMES = ['James','Mary','Robert','Patricia','John','Jennifer','Michael','Linda','David','Elizabeth',
        'William','Barbara','Richard','Susan','Joseph','Jessica','Thomas','Sarah','Christopher','Karen',
        'Charles','Lisa','Daniel','Nancy','Matthew','Betty','Anthony','Margaret','Mark','Sandra',
        'Donald','Ashley','Steven','Dorothy','Andrew','Kimberly','Paul','Emily','Joshua','Donna'];

    private const LAST_NAMES = ['Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez',
        'Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin',
        'Lee','Perez','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson'];

    // Realistic event titles by category
    private const WORK_EVENTS = ['Team Standup','Sprint Planning','Code Review','1:1 with Manager','All Hands Meeting',
        'Architecture Review','Deployment Review','Product Demo','Budget Review','Quarterly Planning',
        'Client Call','Interview','Onboarding Session','Training Workshop','Team Lunch'];

    private const PERSONAL_EVENTS = ['Dentist Appointment','Car Service','Grocery Shopping','Gym','Yoga Class',
        'Dinner Reservation','Movie Night','Book Club','Parent-Teacher Conference','Dog Grooming',
        'Hair Appointment','Home Repair','Birthday Party','BBQ','Game Night'];

    private const HOLIDAY_EVENTS = ['Company Holiday','National Holiday','Office Closed','Half Day','Team Building Day'];

    private const MEETING_LOCATIONS = ['Conference Room A','Conference Room B','Board Room','Zoom','Google Meet',
        'Teams Call','Building 2 Room 101','Cafeteria','Offsite','Remote'];

    public function __construct(
        private readonly CoreServiceFactory $factory,
        private readonly PasswordHasher $passwordHasher = new PasswordHasher(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('users', null, InputOption::VALUE_REQUIRED, 'Number of users to create', '100')
            ->addOption('events', null, InputOption::VALUE_REQUIRED, 'Number of events to create', '10000')
            ->addOption('categories', null, InputOption::VALUE_REQUIRED, 'Number of global categories', '10')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Remove all seeded data (users with login starting with "perf_")')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('cleanup')) {
            return $this->cleanup($io);
        }

        /** @var string $uOpt */
        $uOpt = $input->getOption('users') ?? '100';
        /** @var string $eOpt */
        $eOpt = $input->getOption('events') ?? '10000';
        /** @var string $cOpt */
        $cOpt = $input->getOption('categories') ?? '10';
        $numUsers = (int) $uOpt;
        $numEvents = (int) $eOpt;
        $numCategories = (int) $cOpt;

        $io->title('Seeding Test Data');
        $io->text("Users: {$numUsers}, Events: {$numEvents}, Categories: {$numCategories}");

        $startTime = microtime(true);

        // Step 1: Create categories
        $io->section('Creating categories...');
        $categoryIds = $this->seedCategories($numCategories, $io);
        $io->text(\count($categoryIds) . ' categories created');

        // Step 2: Create users
        $io->section('Creating users...');
        $userLogins = $this->seedUsers($numUsers, $io);
        $io->text(\count($userLogins) . ' users created');

        // Step 3: Create events
        $io->section('Creating events...');
        $eventCount = $this->seedEvents($numEvents, $userLogins, $categoryIds, $io);
        $io->text("{$eventCount} events created");

        $elapsed = round(microtime(true) - $startTime, 1);
        $io->success("Seeding complete in {$elapsed}s. {$eventCount} events across {$numUsers} users.");

        return Command::SUCCESS;
    }

    /**
     * @return list<int> Category IDs
     */
    private function seedCategories(int $count, SymfonyStyle $io): array
    {
        $names = ['Work', 'Personal', 'Meeting', 'Travel', 'Holiday', 'Training',
            'Client', 'Internal', 'Social', 'Health', 'Finance', 'Project Alpha',
            'Project Beta', 'Urgent', 'Optional', 'Recurring', 'One-time',
            'Department', 'Company-wide', 'Team'];

        $colors = ['#3788d8', '#43a047', '#e53935', '#fb8c00', '#8e24aa',
            '#00acc1', '#6d4c41', '#546e7a', '#d81b60', '#1e88e5',
            '#7cb342', '#f4511e', '#3949ab', '#00897b', '#c0ca33'];

        $catRepo = $this->factory->getCategoryRepository();
        $admin = new User('admin', 'Admin', 'User', 'admin@test.com', true, true);
        $ids = [];

        for ($i = 0; $i < min($count, \count($names)); $i++) {
            try {
                $nextId = $catRepo->nextId();
                $cat = new Category($nextId, null, $names[$i], $colors[$i % \count($colors)]);
                $catRepo->save($cat);
                $ids[] = $nextId;
            } catch (\Throwable $e) {
                // Category may already exist
                $existing = $catRepo->findByName($names[$i]);
                if ($existing !== null) {
                    $ids[] = $existing->id();
                }
            }
        }

        return $ids;
    }

    /**
     * @return list<string> User logins
     */
    private function seedUsers(int $count, SymfonyStyle $io): array
    {
        $userService = $this->factory->getUserService();
        $userRepo = $this->factory->getUserRepository();
        $admin = new User('admin', 'Admin', 'User', 'admin@test.com', true, true);
        $logins = [];
        $batchSize = 100;

        $io->progressStart($count);

        for ($i = 0; $i < $count; $i++) {
            $first = self::FIRST_NAMES[$i % \count(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[($i / \count(self::FIRST_NAMES)) % \count(self::LAST_NAMES)];
            $login = 'perf_' . strtolower($first) . '_' . strtolower($last) . '_' . $i;
            $email = "{$login}@perftest.local";
            $isAdmin = $i < max(1, (int) ($count * 0.05)); // 5% admins

            try {
                $user = new User($login, $first, $last, $email, $isAdmin, true);
                $userService->createUser($user, $admin);
                $userRepo->setPassword($login, $this->passwordHasher->hash('perf123'));
                $logins[] = $login;
            } catch (\Throwable) {
                // User may already exist
                $logins[] = $login;
            }

            if ($i % $batchSize === 0) {
                $io->progressAdvance($batchSize);
            }
        }

        $io->progressFinish();
        return $logins;
    }

    /**
     * @param list<string> $userLogins
     * @param list<int>    $categoryIds
     */
    private function seedEvents(int $count, array $userLogins, array $categoryIds, SymfonyStyle $io): int
    {
        $eventRepo = $this->factory->getEventRepository();
        $catRepo = $this->factory->getCategoryRepository();
        $created = 0;
        $batchSize = 500;
        $now = $this->clock->now();

        // Time distribution: events spread across 2 years
        $rangeStart = $now->modify('-1 year');
        $rangeDays = 730; // 2 years

        // Working hours distribution (realistic)
        $workHours = [8, 9, 9, 9, 10, 10, 10, 11, 11, 13, 14, 14, 15, 15, 16];
        $durations = [15, 30, 30, 30, 45, 60, 60, 60, 90, 120]; // minutes

        $io->progressStart($count);

        for ($i = 0; $i < $count; $i++) {
            $login = $userLogins[$i % \count($userLogins)];
            $user = new User($login, '', '', "{$login}@perftest.local", false, true);

            // Determine event type distribution
            $rand = mt_rand(1, 100);
            if ($rand <= 60) {
                // 60% work events
                $title = self::WORK_EVENTS[array_rand(self::WORK_EVENTS)];
                $location = self::MEETING_LOCATIONS[array_rand(self::MEETING_LOCATIONS)];
                $access = AccessLevel::PUBLIC;
                $catId = !empty($categoryIds) ? $categoryIds[mt_rand(0, min(4, \count($categoryIds) - 1))] : null;
            } elseif ($rand <= 85) {
                // 25% personal events
                $title = self::PERSONAL_EVENTS[array_rand(self::PERSONAL_EVENTS)];
                $location = '';
                $access = AccessLevel::PRIVATE;
                $catId = !empty($categoryIds) && \count($categoryIds) > 5 ? $categoryIds[mt_rand(5, min(9, \count($categoryIds) - 1))] : null;
            } elseif ($rand <= 95) {
                // 10% all-day events (holidays, conferences)
                $title = self::HOLIDAY_EVENTS[array_rand(self::HOLIDAY_EVENTS)];
                $location = '';
                $access = AccessLevel::PUBLIC;
                $catId = !empty($categoryIds) ? $categoryIds[min(4, \count($categoryIds) - 1)] : null;
            } else {
                // 5% confidential events
                $title = 'Confidential: ' . self::WORK_EVENTS[array_rand(self::WORK_EVENTS)];
                $location = 'Private Room';
                $access = AccessLevel::CONFIDENTIAL;
                $catId = null;
            }

            // Date: random within 2-year range, weighted toward weekdays
            $dayOffset = mt_rand(0, $rangeDays);
            $date = $rangeStart->modify("+{$dayOffset} days");

            // Skip weekends for work events (70% of the time)
            $dow = (int) $date->format('N'); // 1=Mon, 7=Sun
            if ($dow >= 6 && $rand <= 60 && mt_rand(1, 100) <= 70) {
                $date = $date->modify('next Monday');
            }

            // Time
            $isAllDay = $rand > 85 && $rand <= 95;
            $hour = $isAllDay ? 0 : $workHours[array_rand($workHours)];
            $minute = $isAllDay ? 0 : [0, 0, 0, 15, 30, 30, 45][array_rand([0, 0, 0, 15, 30, 30, 45])];
            $start = $date->setTime($hour, $minute);

            $duration = $isAllDay ? 0 : $durations[array_rand($durations)];

            // Recurrence: 15% of events are recurring
            $rrule = null;
            $eventType = EventType::EVENT;
            if (mt_rand(1, 100) <= 15 && !$isAllDay) {
                $patterns = [
                    'FREQ=DAILY;COUNT=5',
                    'FREQ=WEEKLY;COUNT=8',
                    'FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=12',
                    'FREQ=WEEKLY;COUNT=52',
                    'FREQ=MONTHLY;COUNT=6',
                    'FREQ=MONTHLY;BYMONTHDAY=1;COUNT=12',
                    'FREQ=YEARLY;COUNT=3',
                ];
                $rrule = $patterns[array_rand($patterns)];
                $eventType = EventType::REPEATING_EVENT;
            }

            $uid = "perf-{$i}-" . bin2hex(random_bytes(4)) . '@perftest';

            try {
                $event = new Event(
                    id: new EventId(0),
                    uid: $uid,
                    name: $title,
                    description: $this->generateDescription($title),
                    location: $location,
                    start: $start,
                    duration: $duration,
                    createdBy: $login,
                    type: $eventType,
                    access: $access,
                    allDay: $isAllDay,
                );

                $this->factory->getEventService()->createEvent($event, $user);
                $created++;

                // Assign category (70% of events get one)
                if ($catId !== null && mt_rand(1, 100) <= 70) {
                    $createdEvent = $eventRepo->findByUid($uid);
                    if ($createdEvent !== null) {
                        try {
                            $catRepo->assignToEvent($createdEvent->id(), $login, [$catId]);
                        } catch (\Throwable) {
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Skip on error (duplicate UID, etc.)
                if ($i < 10) {
                    $io->warning("Event {$i}: {$e->getMessage()}");
                }
            }

            if ($i % $batchSize === 0 && $i > 0) {
                $io->progressAdvance($batchSize);
            }
        }

        $io->progressFinish();
        return $created;
    }

    private function generateDescription(string $title): string
    {
        // 50% have a description, 50% empty (realistic)
        if (mt_rand(1, 100) <= 50) {
            return '';
        }

        $descriptions = [
            "Please join us for {$title}. Agenda will be shared beforehand.",
            "Regular {$title} session. Please come prepared with updates.",
            "Reminder: {$title} is scheduled. Contact organizer for questions.",
            "{$title} — please review the attached documents before attending.",
            "This is a recurring {$title}. Check the shared calendar for any changes.",
        ];

        return $descriptions[array_rand($descriptions)];
    }

    private function cleanup(SymfonyStyle $io): int
    {
        $io->section('Cleaning up seeded data...');

        $pdo = $this->factory->getPdo();

        // Delete events by perf_ users
        $stmt = $pdo->query("SELECT COUNT(*) FROM webcal_entry WHERE cal_create_by LIKE 'perf_%'");
        $eventCount = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        $pdo->exec("DELETE FROM webcal_entry_categories WHERE cal_id IN (SELECT cal_id FROM webcal_entry WHERE cal_create_by LIKE 'perf_%')");
        $pdo->exec("DELETE FROM webcal_entry_repeats WHERE cal_id IN (SELECT cal_id FROM webcal_entry WHERE cal_create_by LIKE 'perf_%')");
        $pdo->exec("DELETE FROM webcal_entry_repeats_not WHERE cal_id IN (SELECT cal_id FROM webcal_entry WHERE cal_create_by LIKE 'perf_%')");
        $pdo->exec("DELETE FROM webcal_entry_user WHERE cal_id IN (SELECT cal_id FROM webcal_entry WHERE cal_create_by LIKE 'perf_%')");
        $pdo->exec("DELETE FROM webcal_entry WHERE cal_create_by LIKE 'perf_%'");

        // Delete perf_ users
        $stmt = $pdo->query("SELECT COUNT(*) FROM webcal_user WHERE cal_login LIKE 'perf_%'");
        $userCount = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        $pdo->exec("DELETE FROM webcal_user_pref WHERE cal_login LIKE 'perf_%'");
        $pdo->exec("DELETE FROM webcal_user WHERE cal_login LIKE 'perf_%'");

        $io->success("Cleaned up {$eventCount} events and {$userCount} users.");

        return Command::SUCCESS;
    }
}
