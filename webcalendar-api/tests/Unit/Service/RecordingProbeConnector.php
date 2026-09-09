<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ProbeConnector;

/**
 * Records the connection it was asked for and hands back a PDO that records
 * what was run on it.
 */
final class RecordingProbeConnector implements ProbeConnector
{
    /** @var list<array{dsn: string, user: string, password: string, options: array<int, mixed>}> */
    public array $connections = [];

    public ?RecordingPdo $pdo = null;

    public function __construct(
        private readonly ?\Throwable $connectFailure = null,
        private readonly bool $queryFails = false,
    ) {}

    /** @return array{dsn: string, user: string, password: string, options: array<int, mixed>} */
    public function onlyConnection(): array
    {
        if (\count($this->connections) !== 1) {
            throw new \RuntimeException('expected one connection, got ' . \count($this->connections));
        }

        return $this->connections[0];
    }

    #[\Override]
    public function connect(
        string $dsn,
        string $user,
        #[\SensitiveParameter]
        string $password,
        array $options,
    ): \PDO {
        $this->connections[] = ['dsn' => $dsn, 'user' => $user, 'password' => $password, 'options' => $options];

        if ($this->connectFailure !== null) {
            throw $this->connectFailure;
        }

        return $this->pdo = new RecordingPdo($this->queryFails);
    }
}

/**
 * A real PDO over an in-memory database, so statements behave, that also
 * remembers what it was asked to run.
 */
final class RecordingPdo extends \PDO
{
    /** @var list<string> */
    public array $queries = [];

    /** @var list<string> */
    public array $statements = [];

    public function __construct(private readonly bool $queryFails = false)
    {
        parent::__construct('sqlite::memory:');
    }

    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->queries[] = $query;

        if ($this->queryFails) {
            throw new \PDOException('server has gone away');
        }

        return parent::query($query);
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        return 0;
    }
}
