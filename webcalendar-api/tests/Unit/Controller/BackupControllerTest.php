<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\BackupController;
use App\Security\WebCalendarUser;
use App\Service\BackupService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Database backups.
 *
 * Nothing executed a line of it. Two of the five routes take a filename out of
 * the URL and join it to a directory, and the fifth takes a file upload -- and
 * an upload larger than PHP's own limit, which a database dump usually is,
 * arrives looking like a file that is simply not there.
 */
final class BackupControllerTest extends TestCase
{
    private const NOW = '2026-09-11T12:00:00+00:00';

    private string $projectDir = '';
    private string $dbPath = '';
    private BackupService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/wctng_backup_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/var/backups', 0o750, true);

        $this->dbPath = $this->projectDir . '/db.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (a TEXT)');
        $pdo->exec("INSERT INTO t (a) VALUES ('original')");

        $this->service = new BackupService(
            $pdo,
            'sqlite:///' . $this->dbPath,
            $this->projectDir,
            new MockClock(self::NOW),
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->projectDir === '' || !is_dir($this->projectDir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->projectDir);
    }

    private function controller(): BackupController
    {
        return new BackupController($this->service);
    }

    private function backupDir(): string
    {
        return $this->projectDir . '/var/backups';
    }

    private static function admin(): WebCalendarUser
    {
        return new WebCalendarUser(new User('sysop', 'Sys', 'Op', 'sysop@x.com', true, true), null);
    }

    private static function ordinaryUser(): WebCalendarUser
    {
        return new WebCalendarUser(new User('bob', 'Bob', 'J', 'bob@x.com', false, true), null);
    }

    /** @return array<string, mixed> */
    private static function payload(Response $response): array
    {
        $body = $response->getContent();
        self::assertIsString($body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function restoreRequest(mixed $files, string $confirm = 'RESTORE'): Request
    {
        return Request::create(
            '/api/v2/admin/restore',
            'POST',
            ['confirm' => $confirm],
            [],
            $files === null ? [] : ['file' => $files],
        );
    }

    private function uploadOf(string $contents, int $error = \UPLOAD_ERR_OK): UploadedFile
    {
        $path = $this->projectDir . '/upload_' . bin2hex(random_bytes(3));
        file_put_contents($path, $contents);

        return new UploadedFile($path, 'backup.sqlite', null, $error, true);
    }

    // ------------------------------------------------------------------ access

    #[DataProvider('callersWithoutAccess')]
    public function testEveryRouteIsAdministratorsOnly(\Closure $call, ?WebCalendarUser $caller): void
    {
        $this->assertSame(403, $call($this->controller(), $caller)->getStatusCode());
    }

    /** @return iterable<string, array{\Closure, WebCalendarUser|null}> */
    public static function callersWithoutAccess(): iterable
    {
        $calls = [
            'create' => static fn(BackupController $c, ?WebCalendarUser $u): Response => $c->create($u),
            'list' => static fn(BackupController $c, ?WebCalendarUser $u): Response => $c->list($u),
            'download' => static fn(BackupController $c, ?WebCalendarUser $u): Response => $c->download('x.sql', $u),
            'delete' => static fn(BackupController $c, ?WebCalendarUser $u): Response => $c->delete('x.sql', $u),
            'restore' => static fn(BackupController $c, ?WebCalendarUser $u): Response
                => $c->restore(self::restoreRequest(null), $u),
        ];

        foreach ($calls as $name => $call) {
            yield "{$name}, anonymous" => [$call, null];
            yield "{$name}, not an admin" => [$call, self::ordinaryUser()];
        }
    }

    // ------------------------------------------------------------ create, list

    public function testCreatingABackupWritesAFileAndSaysWhereToGetIt(): void
    {
        $response = $this->controller()->create(self::admin());

        $this->assertSame(201, $response->getStatusCode());
        $data = self::payload($response)['data'];
        $this->assertStringStartsWith('webcalendar-backup-', $data['filename']);
        $this->assertSame("/api/v2/admin/backup/{$data['filename']}", $data['download_url']);
        $this->assertGreaterThan(0, $data['size_bytes']);
        $this->assertFileExists($this->backupDir() . '/' . $data['filename']);
    }

    public function testTheListShowsWhatHasBeenBackedUp(): void
    {
        $filename = self::payload($this->controller()->create(self::admin()))['data']['filename'];

        $listed = self::payload($this->controller()->list(self::admin()))['data'];

        $this->assertSame([$filename], array_column($listed, 'filename'));
    }

    public function testAnEmptyShelfListsNothing(): void
    {
        $this->assertSame([], self::payload($this->controller()->list(self::admin()))['data']);
    }

    // ---------------------------------------------------------------- download

    public function testABackupCanBeDownloaded(): void
    {
        $filename = self::payload($this->controller()->create(self::admin()))['data']['filename'];

        $response = $this->controller()->download($filename, self::admin());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame("attachment; filename=\"{$filename}\"", $response->headers->get('Content-Disposition'));
    }

    #[DataProvider('filenamesThatAreNotOne')]
    public function testDownloadRefusesAFilenameThatIsNotOne(string $filename): void
    {
        $this->assertSame(400, $this->controller()->download($filename, self::admin())->getStatusCode());
    }

    #[DataProvider('filenamesThatAreNotOne')]
    public function testDeleteRefusesAFilenameThatIsNotOne(string $filename): void
    {
        $this->assertSame(400, $this->controller()->delete($filename, self::admin())->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function filenamesThatAreNotOne(): iterable
    {
        yield 'a path' => ['../../../etc/passwd'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'spaces' => ['back up.sql'];
        yield 'empty' => [''];
    }

    /**
     * isValidFilename() rejects anything with a slash in it, which stops the
     * obvious traversal -- but "." and ".." are made only of characters it
     * allows, and both name the directory rather than a file in it.
     */
    #[DataProvider('namesOfDirectoriesRatherThanFiles')]
    public function testDownloadingSomethingThatIsNotAFileIsNotFound(string $filename): void
    {
        $this->assertSame(404, $this->controller()->download($filename, self::admin())->getStatusCode());
    }

    #[DataProvider('namesOfDirectoriesRatherThanFiles')]
    public function testDeletingSomethingThatIsNotAFileIsNotFound(string $filename): void
    {
        $this->assertSame(404, $this->controller()->delete($filename, self::admin())->getStatusCode());
        $this->assertDirectoryExists($this->backupDir());
    }

    /** @return iterable<string, array{string}> */
    public static function namesOfDirectoriesRatherThanFiles(): iterable
    {
        yield 'the directory itself' => ['.'];
        yield 'its parent' => ['..'];
        yield 'a directory inside it' => ['nested'];
    }

    public function testDownloadingSomethingThatIsNotThereIsNotFound(): void
    {
        $this->assertSame(404, $this->controller()->download('webcalendar-backup-nope.sqlite', self::admin())->getStatusCode());
    }

    // ------------------------------------------------------------------ delete

    public function testABackupCanBeDeleted(): void
    {
        $filename = self::payload($this->controller()->create(self::admin()))['data']['filename'];

        $response = $this->controller()->delete($filename, self::admin());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($filename, self::payload($response)['data']['deleted']);
        $this->assertFileDoesNotExist($this->backupDir() . '/' . $filename);
    }

    public function testDeletingSomethingThatIsNotThereIsNotFound(): void
    {
        $this->assertSame(404, $this->controller()->delete('webcalendar-backup-nope.sqlite', self::admin())->getStatusCode());
    }

    // ----------------------------------------------------------------- restore

    #[DataProvider('confirmationsThatAreNot')]
    public function testRestoringNeedsTheWordInFull(string $confirm): void
    {
        $response = $this->controller()->restore(
            self::restoreRequest($this->uploadOf('x'), $confirm),
            self::admin(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('original', $this->currentRow(), 'the database must not have been touched');
    }

    /** @return iterable<string, array{string}> */
    public static function confirmationsThatAreNot(): iterable
    {
        yield 'nothing' => [''];
        yield 'the wrong word' => ['yes'];
        yield 'the wrong case' => ['restore'];
        yield 'padded' => [' RESTORE '];
    }

    public function testRestoringNeedsAFile(): void
    {
        $response = $this->controller()->restore(self::restoreRequest(null), self::admin());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('No file uploaded', self::payload($response)['error']['message']);
    }

    /**
     * A database dump is usually larger than upload_max_filesize, and PHP
     * hands over an UploadedFile carrying an error code and a path to nothing.
     * That reached the service as a missing file and came back a 500 saying
     * the backup was not found -- which is not what went wrong.
     */
    #[DataProvider('uploadsThatFailed')]
    public function testAnUploadThatDidNotArriveSaysSo(int $error): void
    {
        $response = $this->controller()->restore(
            self::restoreRequest($this->uploadOf('x', $error)),
            self::admin(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringNotContainsString('not found', self::payload($response)['error']['message']);
        $this->assertSame('original', $this->currentRow());
    }

    /** @return iterable<string, array{int}> */
    public static function uploadsThatFailed(): iterable
    {
        yield 'bigger than php allows' => [\UPLOAD_ERR_INI_SIZE];
        yield 'bigger than the form allows' => [\UPLOAD_ERR_FORM_SIZE];
        yield 'only part of it arrived' => [\UPLOAD_ERR_PARTIAL];
        yield 'nowhere to put it' => [\UPLOAD_ERR_NO_TMP_DIR];
        yield 'could not be written' => [\UPLOAD_ERR_CANT_WRITE];
    }

    public function testSeveralFilesAtOnceIsNotAFile(): void
    {
        // files->get() hands back an array for a repeated field, and the
        // method called on it next only exists on one file.
        $response = $this->controller()->restore(
            self::restoreRequest([$this->uploadOf('a'), $this->uploadOf('b')]),
            self::admin(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('original', $this->currentRow());
    }

    public function testAConfirmedRestorePutsTheUploadedDatabaseInPlace(): void
    {
        $replacement = $this->projectDir . '/replacement.sqlite';
        $other = new \PDO('sqlite:' . $replacement);
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->exec('CREATE TABLE t (a TEXT)');
        $other->exec("INSERT INTO t (a) VALUES ('restored')");
        unset($other);

        $response = $this->controller()->restore(
            self::restoreRequest($this->uploadOf((string) file_get_contents($replacement))),
            self::admin(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('restored', self::payload($response)['data']['status']);
        $this->assertSame('restored', $this->currentRow());
    }

    private function currentRow(): string
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $value = $pdo->query('SELECT a FROM t LIMIT 1')?->fetchColumn();

        return \is_string($value) ? $value : '';
    }
}
