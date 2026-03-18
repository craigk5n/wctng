<?php

declare(strict_types=1);

namespace App\Poll;

final readonly class PollRepository
{
    public const SCHEMA_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS scheduling_polls (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            creator_login VARCHAR(60) NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT,
            status VARCHAR(10) NOT NULL DEFAULT 'open',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS scheduling_poll_options (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            poll_id INTEGER NOT NULL,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL
        );
        CREATE TABLE IF NOT EXISTS scheduling_poll_votes (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            option_id INTEGER NOT NULL,
            voter_login VARCHAR(60) NOT NULL,
            vote VARCHAR(5) NOT NULL DEFAULT 'yes'
        )
    SQL;

    public const SCHEMA_SQL_SQLITE = <<<'SQL'
        CREATE TABLE IF NOT EXISTS scheduling_polls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            creator_login VARCHAR(60) NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT,
            status VARCHAR(10) NOT NULL DEFAULT 'open',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS scheduling_poll_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id INTEGER NOT NULL,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL
        );
        CREATE TABLE IF NOT EXISTS scheduling_poll_votes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            option_id INTEGER NOT NULL,
            voter_login VARCHAR(60) NOT NULL,
            vote VARCHAR(5) NOT NULL DEFAULT 'yes'
        )
    SQL;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param list<array{start: string, end: string}> $options
     */
    public function createPoll(string $creator, string $title, string $description, array $options): int
    {
        $this->ensureTable();
        $this->pdo->prepare(
            'INSERT INTO scheduling_polls (creator_login, title, description) VALUES (:creator, :title, :desc)'
        )->execute(['creator' => $creator, 'title' => $title, 'desc' => $description]);

        $pollId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO scheduling_poll_options (poll_id, start_datetime, end_datetime) VALUES (:poll, :start, :end)'
        );
        foreach ($options as $opt) {
            $stmt->execute(['poll' => $pollId, 'start' => $opt['start'], 'end' => $opt['end']]);
        }

        return $pollId;
    }

    /**
     * @return array{id: int, creator: string, title: string, description: string, status: string, created_at: string, options: list<array{id: int, start: string, end: string, votes: list<array{voter: string, vote: string}>, yes_count: int}>}|null
     */
    public function getPoll(int $id): ?array
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT * FROM scheduling_polls WHERE id = :id');
        $stmt->execute(['id' => $id]);
        /** @var array<string, string|int|null>|false $poll */
        $poll = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($poll)) return null;

        $optStmt = $this->pdo->prepare('SELECT * FROM scheduling_poll_options WHERE poll_id = :poll ORDER BY start_datetime');
        $optStmt->execute(['poll' => $id]);

        /** @var list<array{id: int, start: string, end: string, votes: list<array{voter: string, vote: string}>, yes_count: int}> $options */
        $options = [];
        /** @var array<string, string|int|null>|false $row */
        $row = $optStmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $optId = \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;

            $voteStmt = $this->pdo->prepare('SELECT voter_login, vote FROM scheduling_poll_votes WHERE option_id = :opt');
            $voteStmt->execute(['opt' => $optId]);
            /** @var list<array{voter: string, vote: string}> $votes */
            $votes = [];
            /** @var array<string, string|int|null>|false $vRow */
            $vRow = $voteStmt->fetch(\PDO::FETCH_ASSOC);
            while (\is_array($vRow)) {
                $votes[] = [
                    'voter' => \is_string($vRow['voter_login'] ?? null) ? $vRow['voter_login'] : '',
                    'vote' => \is_string($vRow['vote'] ?? null) ? $vRow['vote'] : 'yes',
                ];
                /** @var array<string, string|int|null>|false $vRow */
                $vRow = $voteStmt->fetch(\PDO::FETCH_ASSOC);
            }

            $options[] = [
                'id' => $optId,
                'start' => \is_string($row['start_datetime'] ?? null) ? $row['start_datetime'] : '',
                'end' => \is_string($row['end_datetime'] ?? null) ? $row['end_datetime'] : '',
                'votes' => $votes,
                'yes_count' => \count(array_filter($votes, static fn (array $v): bool => $v['vote'] === 'yes')),
            ];

            /** @var array<string, string|int|null>|false $row */
            $row = $optStmt->fetch(\PDO::FETCH_ASSOC);
        }

        return [
            'id' => \is_numeric($poll['id'] ?? null) ? (int) $poll['id'] : 0,
            'creator' => \is_string($poll['creator_login'] ?? null) ? $poll['creator_login'] : '',
            'title' => \is_string($poll['title'] ?? null) ? $poll['title'] : '',
            'description' => \is_string($poll['description'] ?? null) ? $poll['description'] : '',
            'status' => \is_string($poll['status'] ?? null) ? $poll['status'] : 'open',
            'created_at' => \is_string($poll['created_at'] ?? null) ? $poll['created_at'] : '',
            'options' => $options,
        ];
    }

    /**
     * @param array<int, string> $votes Map of option_id => vote (yes/maybe/no)
     */
    public function castVotes(int $pollId, string $voterLogin, array $votes): void
    {
        $this->ensureTable();

        // Remove existing votes by this voter for this poll's options
        $this->pdo->prepare(
            'DELETE FROM scheduling_poll_votes WHERE voter_login = :voter AND option_id IN (SELECT id FROM scheduling_poll_options WHERE poll_id = :poll)'
        )->execute(['voter' => $voterLogin, 'poll' => $pollId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO scheduling_poll_votes (option_id, voter_login, vote) VALUES (:opt, :voter, :vote)'
        );
        foreach ($votes as $optionId => $vote) {
            $stmt->execute(['opt' => $optionId, 'voter' => $voterLogin, 'vote' => $vote]);
        }
    }

    public function closePoll(int $id): void
    {
        $this->pdo->prepare("UPDATE scheduling_polls SET status = 'closed' WHERE id = :id")->execute(['id' => $id]);
    }

    /** @return list<array{id: int, title: string, status: string, created_at: string}> */
    public function listByCreator(string $login): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT * FROM scheduling_polls WHERE creator_login = :login ORDER BY created_at DESC');
        $stmt->execute(['login' => $login]);

        /** @var list<array{id: int, title: string, status: string, created_at: string}> $polls */
        $polls = [];
        /** @var array<string, string|int|null>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $polls[] = [
                'id' => \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'title' => \is_string($row['title'] ?? null) ? $row['title'] : '',
                'status' => \is_string($row['status'] ?? null) ? $row['status'] : 'open',
                'created_at' => \is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
            ];
            /** @var array<string, string|int|null>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        return $polls;
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite' ? self::SCHEMA_SQL_SQLITE : self::SCHEMA_SQL;
        // Execute each statement separately
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $this->pdo->exec($stmt);
            }
        }
    }
}
