<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Database backup and restore service.
 * Supports MySQL (via mysqldump/mysql) and SQLite (file copy).
 */
final class BackupService
{
    private readonly string $backupDir;
    private readonly string $driver;

    public function __construct(
        \PDO $pdo,
        private readonly string $databaseUrl,
        string $projectDir,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        $this->backupDir = $projectDir . '/var/backups';
        $driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->driver = \is_string($driverName) ? $driverName : 'unknown';
    }

    /**
     * Create a database backup.
     *
     * @return array{filename: string, path: string, size_bytes: int, created_at: string}
     */
    public function createBackup(): array
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0o750, true);
        }

        $timestamp = $this->clock->now()->format('Y-m-d_His');

        if ($this->driver === 'sqlite') {
            return $this->backupSqlite($timestamp);
        }

        return $this->backupMysql($timestamp);
    }

    /**
     * Restore a database from a backup file.
     *
     * @return array{status: string, duration_ms: float}
     */
    public function restore(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException('Backup file not found');
        }

        $start = microtime(true);

        if ($this->driver === 'sqlite') {
            $this->restoreSqlite($filePath);
        } else {
            $this->restoreMysql($filePath);
        }

        $durationMs = round((microtime(true) - $start) * 1000, 1);

        return ['status' => 'restored', 'duration_ms' => $durationMs];
    }

    /**
     * List existing backup files.
     *
     * @return list<array{filename: string, size_bytes: int, created_at: string}>
     */
    public function listBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $files = glob($this->backupDir . '/webcalendar-backup-*');
        if ($files === false) {
            return [];
        }

        $result = [];
        foreach ($files as $file) {
            $stat = stat($file);
            $result[] = [
                'filename' => basename($file),
                'size_bytes' => $stat !== false ? $stat['size'] : 0,
                'created_at' => $stat !== false ? date('c', $stat['mtime']) : '',
            ];
        }

        // Sort newest first
        usort($result, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $result;
    }

    /**
     * Delete backup files older than the specified number of days.
     */
    public function cleanupOld(int $days = 7): int
    {
        $cutoff = time() - ($days * 86400);
        $deleted = 0;

        $files = glob($this->backupDir . '/webcalendar-backup-*');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            $stat = stat($file);
            if ($stat !== false && $stat['mtime'] < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function getBackupDir(): string
    {
        return $this->backupDir;
    }

    /**
     * Validate a filename to prevent path traversal.
     */
    public static function isValidFilename(string $filename): bool
    {
        return (bool) preg_match('/^[\w\-\.]+$/', $filename);
    }

    /**
     * @return array{filename: string, path: string, size_bytes: int, created_at: string}
     */
    private function backupSqlite(string $timestamp): array
    {
        $parsed = parse_url($this->databaseUrl);
        $dbPath = isset($parsed['path']) ? ltrim($parsed['path'], '/') : '';

        if ($dbPath === '' || !file_exists($dbPath)) {
            // Try to extract from DSN-style URL
            if (preg_match('/sqlite.*?:(.+)/', $this->databaseUrl, $m)) {
                $dbPath = $m[1];
            }
        }

        if ($dbPath === '' || !file_exists($dbPath)) {
            throw new \RuntimeException('Cannot determine SQLite database path');
        }

        $filename = "webcalendar-backup-{$timestamp}.db";
        $dest = $this->backupDir . '/' . $filename;

        copy($dbPath, $dest);

        $size = filesize($dest);

        return [
            'filename' => $filename,
            'path' => $dest,
            'size_bytes' => $size !== false ? $size : 0,
            'created_at' => $this->clock->now()->format('c'),
        ];
    }

    /**
     * @return array{filename: string, path: string, size_bytes: int, created_at: string}
     */
    private function backupMysql(string $timestamp): array
    {
        $parsed = $this->parseMysqlUrl();
        $filename = "webcalendar-backup-{$timestamp}.sql";
        $dest = $this->backupDir . '/' . $filename;

        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s',
            escapeshellarg($parsed['host']),
            escapeshellarg((string) $parsed['port']),
            escapeshellarg($parsed['user']),
            escapeshellarg($parsed['pass']),
            escapeshellarg($parsed['dbname']),
            escapeshellarg($dest),
        );

        $exitCode = 0;
        $output = [];
        exec($cmd . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('mysqldump failed: ' . implode("\n", $output));
        }

        $size = filesize($dest);

        return [
            'filename' => $filename,
            'path' => $dest,
            'size_bytes' => $size !== false ? $size : 0,
            'created_at' => $this->clock->now()->format('c'),
        ];
    }

    private function restoreSqlite(string $filePath): void
    {
        $parsed = parse_url($this->databaseUrl);
        $dbPath = isset($parsed['path']) ? ltrim($parsed['path'], '/') : '';

        if ($dbPath === '' || !file_exists($dbPath)) {
            if (preg_match('/sqlite.*?:(.+)/', $this->databaseUrl, $m)) {
                $dbPath = $m[1];
            }
        }

        if ($dbPath === '') {
            throw new \RuntimeException('Cannot determine SQLite database path');
        }

        // Backup current before replacing
        $backupCurrent = $dbPath . '.pre-restore.' . $this->clock->now()->format('His');
        copy($dbPath, $backupCurrent);

        copy($filePath, $dbPath);
    }

    private function restoreMysql(string $filePath): void
    {
        $parsed = $this->parseMysqlUrl();

        $cmd = sprintf(
            'mysql --host=%s --port=%s --user=%s --password=%s %s < %s',
            escapeshellarg($parsed['host']),
            escapeshellarg((string) $parsed['port']),
            escapeshellarg($parsed['user']),
            escapeshellarg($parsed['pass']),
            escapeshellarg($parsed['dbname']),
            escapeshellarg($filePath),
        );

        $exitCode = 0;
        $output = [];
        exec($cmd . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('mysql restore failed: ' . implode("\n", $output));
        }
    }

    /**
     * @return array{host: string, port: int, user: string, pass: string, dbname: string}
     */
    private function parseMysqlUrl(): array
    {
        $parsed = parse_url($this->databaseUrl);

        return [
            'host' => $parsed['host'] ?? '127.0.0.1',
            'port' => $parsed['port'] ?? 3306,
            'user' => $parsed['user'] ?? 'root',
            'pass' => $parsed['pass'] ?? '',
            'dbname' => ltrim($parsed['path'] ?? '', '/'),
        ];
    }
}
