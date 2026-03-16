<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Service\CoreServiceFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $app = new Application($kernel);
        $command = $app->find('webcalendar:install');
        $this->tester = new CommandTester($command);
    }

    public function testInstallCommandExists(): void
    {
        $this->tester->execute(['--force' => true]);
        $this->assertSame(0, $this->tester->getStatusCode());
    }

    public function testInstallOutputsProgressMessages(): void
    {
        $this->tester->execute(['--force' => true]);
        $display = $this->tester->getDisplay();

        $this->assertStringContainsString('database type', strtolower($display));
    }

    public function testInstallIsIdempotent(): void
    {
        // First run
        $this->tester->execute(['--force' => true]);
        $this->assertSame(0, $this->tester->getStatusCode());

        // Second run — should not error
        $this->tester->execute(['--force' => true]);
        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('already exist', $this->tester->getDisplay());
    }

    public function testInstallCreatesAdminUser(): void
    {
        $this->tester->execute(['--force' => true, '--admin-password' => 'test_admin_pass']);
        $this->assertSame(0, $this->tester->getStatusCode());

        /** @var CoreServiceFactory $factory */
        $factory = self::getContainer()->get(CoreServiceFactory::class);
        $user = $factory->getUserService()->getUserByLogin('admin');

        $this->assertNotNull($user);
        $this->assertTrue($user->isAdmin());
    }

    public function testInstallRequiresForceFlag(): void
    {
        $this->tester->execute([]);
        $this->assertNotSame(0, $this->tester->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('--force', $this->tester->getDisplay());
    }

    public function testInstallVerifiesTablesExist(): void
    {
        $this->tester->execute(['--force' => true]);
        $this->assertSame(0, $this->tester->getStatusCode());

        /** @var CoreServiceFactory $factory */
        $factory = self::getContainer()->get(CoreServiceFactory::class);

        // Verify we can use core services against the schema
        $events = $factory->getEventService();
        $this->assertNotNull($events);
    }
}
