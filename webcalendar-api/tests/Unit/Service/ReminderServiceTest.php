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
    private function createServiceWithSchema(): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Load schema so webcal_config etc. exist
        $schemaPath = realpath(__DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql');
        if ($schemaPath !== false) {
            $schema = file_get_contents($schemaPath);
            if ($schema !== false) {
                $clean = (string) preg_replace('/--[^\n]*/', '', $schema);
                /** @var string[] $statements */
                $statements = preg_split('/;\s*\n/', $clean) ?? [];
                foreach ($statements as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt !== '') {
                        try {
                            $pdo->exec($stmt);
                        } catch (\PDOException) {
                        }
                    }
                }
            }
        }

        $factory = new CoreServiceFactory($pdo, 'test');
        $mailer = $this->createMock(MailerInterface::class);
        $emailService = new EmailService($mailer, 'from@test.com', 'WebCal');
        $service = new ReminderService($factory, $emailService, 'http://localhost');

        return [$pdo, $factory, $service];
    }

    public function testSendRemindersReturnsZeroWithNoUsers(): void
    {
        [, , $service] = $this->createServiceWithSchema();
        $count = $service->sendReminders();
        $this->assertSame(0, $count);
    }

    public function testTrackingTableCreatedAutomatically(): void
    {
        [$pdo, , $service] = $this->createServiceWithSchema();
        $service->sendReminders();

        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='reminder_sent'");
        $this->assertNotFalse($stmt);
        $this->assertNotFalse($stmt->fetch());
    }

    public function testDuplicateReminderNotSent(): void
    {
        [$pdo, , $service] = $this->createServiceWithSchema();

        $pdo->exec('CREATE TABLE IF NOT EXISTS reminder_sent (event_id INTEGER, user_login VARCHAR(60), sent_at INTEGER, PRIMARY KEY(event_id, user_login))');
        $pdo->exec("INSERT INTO reminder_sent VALUES (1, 'alice', " . time() . ')');

        $method = new \ReflectionMethod($service, 'isReminderSent');
        $this->assertTrue($method->invoke($service, $pdo, 1, 'alice'));
        $this->assertFalse($method->invoke($service, $pdo, 2, 'alice'));
    }

    public function testRenderReminderEmailContainsTitle(): void
    {
        [, , $service] = $this->createServiceWithSchema();

        $method = new \ReflectionMethod($service, 'renderReminderEmail');
        $html = $method->invoke($service, 'Team Standup', '2026-03-17', '09:00', 'Room A', 'admin');

        $this->assertStringContainsString('Team Standup', $html);
        $this->assertStringContainsString('2026-03-17', $html);
        $this->assertStringContainsString('09:00', $html);
        $this->assertStringContainsString('Room A', $html);
    }
}
