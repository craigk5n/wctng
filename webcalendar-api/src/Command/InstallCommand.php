<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CoreServiceFactory;
use App\Service\PdoFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WebCalendar\Core\Domain\Entity\User;

#[AsCommand(
    name: 'webcalendar:install',
    description: 'Initialize the WebCalendar database schema and create a default admin user.',
)]
final class InstallCommand extends Command
{
    private const DEFAULT_ADMIN_LOGIN = 'admin';
    private const DEFAULT_ADMIN_PASSWORD = 'admin';

    public function __construct(
        private readonly string $databaseUrl,
        private readonly CoreServiceFactory $coreServiceFactory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Required for non-interactive execution')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Password for the admin user', self::DEFAULT_ADMIN_PASSWORD);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force')) {
            $io->error('This command modifies the database. Use --force to confirm.');
            return Command::FAILURE;
        }

        $driver = $this->detectDatabaseType();
        $io->info("Detected database type: {$driver}");

        if ($this->tablesExist()) {
            $io->note('Schema tables already exist — skipping schema creation.');
        } else {
            $this->createSchema($io, $driver);
        }

        /** @var string $adminPassword */
        $adminPassword = $input->getOption('admin-password') ?? self::DEFAULT_ADMIN_PASSWORD;
        $this->ensureAdminUser($io, $adminPassword);

        $io->success('WebCalendar installation complete.');

        return Command::SUCCESS;
    }

    private function detectDatabaseType(): string
    {
        /** @var array{scheme?: string} $parts */
        $parts = parse_url($this->databaseUrl);
        $scheme = $parts['scheme'] ?? 'mysql';

        return match ($scheme) {
            'mysql', 'mysql2' => 'mysql',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'sqlite', 'sqlite3' => 'sqlite',
            default => 'mysql',
        };
    }

    private function tablesExist(): bool
    {
        $pdo = PdoFactory::createFromUrl($this->databaseUrl);
        $driver = $this->detectDatabaseType();

        try {
            if ($driver === 'mysql') {
                $stmt = $pdo->query("SHOW TABLES LIKE 'webcal_entry'");
            } elseif ($driver === 'pgsql') {
                $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE tablename = 'webcal_entry'");
            } else {
                $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='webcal_entry'");
            }

            if ($stmt === false) {
                return false;
            }

            return $stmt->rowCount() > 0 || $stmt->fetch() !== false;
        } catch (\PDOException) {
            return false;
        }
    }

    private function createSchema(SymfonyStyle $io, string $driver): void
    {
        $schemaFile = $this->getSchemaFilePath($driver);

        if (!file_exists($schemaFile)) {
            $io->error("Schema file not found: {$schemaFile}");
            return;
        }

        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            $io->error("Could not read schema file: {$schemaFile}");
            return;
        }

        $io->text("Loading schema from: {$schemaFile}");

        $pdo = PdoFactory::createFromUrl($this->databaseUrl);

        // Split by semicolons and execute each statement
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            static fn (string $s): bool => $s !== '',
        );

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        $io->text('Schema created successfully.');
    }

    private function getSchemaFilePath(string $driver): string
    {
        // Look for schema files in the webcalendar-core package
        $reflection = new \ReflectionClass(\WebCalendar\Core\Application\Service\EventService::class);
        $coreDir = \dirname((string) $reflection->getFileName(), 4);
        $fileName = match ($driver) {
            'pgsql' => 'postgresql-schema.sql',
            'sqlite' => 'sqlite-schema.sql',
            default => 'mysql-schema.sql',
        };

        return $coreDir . '/Infrastructure/Persistence/' . $fileName;
    }

    private function ensureAdminUser(SymfonyStyle $io, #[\SensitiveParameter] string $password): void
    {
        $userService = $this->coreServiceFactory->getUserService();
        $existing = $userService->getUserByLogin(self::DEFAULT_ADMIN_LOGIN);

        if ($existing !== null) {
            $io->note('Admin user already exists — skipping.');
            return;
        }

        $admin = new User(
            login: self::DEFAULT_ADMIN_LOGIN,
            firstName: 'Admin',
            lastName: 'User',
            email: 'admin@example.com',
            isAdmin: true,
            isEnabled: true,
        );

        // Use the admin user as both the actor and the user being created
        $userService->createUser($admin, $admin);

        // Set the password hash
        $hash = $userService->hashPassword($password);
        $this->coreServiceFactory->getUserRepository()->setPassword(self::DEFAULT_ADMIN_LOGIN, $hash);

        $io->text('Admin user created (login: admin).');
    }
}
