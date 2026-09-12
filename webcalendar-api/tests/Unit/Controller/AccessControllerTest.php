<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AccessController;
use App\Security\WebCalendarUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Domain\Entity\User;

/**
 * Who may see and change another user's calendar.
 *
 * Infection reported that no source from this controller was executed by the
 * unit suite at all, so none of it had a score: every decision here could be
 * changed without a failure. Its functional tests cover the happy path -- an
 * empty list, a grant, a regrant, and the 401 -- and the mutation run does not
 * execute them. The part with teeth was never covered anywhere: the owner of a
 * permission row is taken from the session, never from the request, which is
 * the only thing stopping a caller granting themselves rights on somebody
 * else's calendar.
 */
final class AccessControllerTest extends TestCase
{
    private \PDO $pdo;
    private AccessController $controller;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE webcal_access_user (
                cal_login VARCHAR(60) NOT NULL,
                cal_other_user VARCHAR(60) NOT NULL,
                cal_can_view INT NOT NULL DEFAULT 0,
                cal_can_edit INT NOT NULL DEFAULT 0,
                cal_can_approve INT NOT NULL DEFAULT 0,
                cal_can_invite CHAR(1) NOT NULL DEFAULT \'Y\',
                cal_can_email CHAR(1) NOT NULL DEFAULT \'Y\',
                cal_see_time_only CHAR(1) NOT NULL DEFAULT \'N\',
                PRIMARY KEY (cal_login, cal_other_user)
            )',
        );

        $this->controller = new AccessController($this->pdo);
    }

    private static function user(string $login): WebCalendarUser
    {
        return new WebCalendarUser(
            new User($login, ucfirst($login), 'Smith', $login . '@example.com', false, true),
            null,
        );
    }

    /** @param array<string, mixed> $body */
    private function set(string $target, array $body, string $as = 'alice'): JsonResponse
    {
        return $this->controller->set(
            $target,
            Request::create('/api/v2/access/users/' . $target, 'PUT', [], [], [], [], (string) json_encode($body)),
            self::user($as),
        );
    }

    /** @return list<array<string, mixed>> */
    private function storedRows(): array
    {
        $rows = $this->pdo
            ->query('SELECT cal_login, cal_other_user, cal_can_view, cal_can_edit, cal_see_time_only
                     FROM webcal_access_user ORDER BY cal_login, cal_other_user')
            ?->fetchAll(\PDO::FETCH_ASSOC);

        return \is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed> */
    private static function bodyOf(JsonResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    // ------------------------------------------------------------- refused

    public function testListingNeedsSomebodyToBeSignedIn(): void
    {
        self::assertSame(401, $this->controller->list(null)->getStatusCode());
    }

    public function testGrantingNeedsSomebodyToBeSignedIn(): void
    {
        $request = Request::create('/api/v2/access/users/bob', 'PUT', [], [], [], [], '{"can_view":true}');

        self::assertSame(401, $this->controller->set('bob', $request, null)->getStatusCode());
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $request = Request::create('/api/v2/access/users/bob', 'PUT', [], [], [], [], 'not json at all');

        $response = $this->controller->set('bob', $request, self::user('alice'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->storedRows(), 'nothing should have been written');
    }

    // ------------------------------------------------------- who owns what

    public function testAGrantIsRecordedAgainstTheCallersOwnCalendar(): void
    {
        // The owner comes from the session and the subject from the path. If
        // they were ever taken the other way round, or both from the request,
        // a caller could hand themselves the run of somebody else's calendar.
        $this->set('bob', ['can_view' => true, 'can_edit' => true]);

        self::assertSame([[
            'cal_login' => 'alice',
            'cal_other_user' => 'bob',
            'cal_can_view' => 1,
            'cal_can_edit' => 1,
            'cal_see_time_only' => 'N',
        ]], $this->storedRows());
    }

    public function testTwoPeopleGrantingRightsOverTheSameUserDoNotCollide(): void
    {
        $this->set('bob', ['can_view' => true], as: 'alice');
        $this->set('bob', ['can_view' => true, 'can_edit' => true], as: 'carol');

        $rows = $this->storedRows();
        self::assertCount(2, $rows);
        self::assertSame('alice', $rows[0]['cal_login']);
        self::assertSame('carol', $rows[1]['cal_login']);
        self::assertSame(0, $rows[0]['cal_can_edit'], "carol's grant must not touch alice's");
    }

    public function testAListingShowsOnlyTheCallersOwnGrants(): void
    {
        // Rows are keyed by owner, and the query filters on the session user.
        // Without that filter every user would be shown everyone's sharing.
        $this->set('bob', ['can_view' => true], as: 'alice');
        $this->set('dave', ['can_view' => true, 'can_edit' => true], as: 'carol');

        /** @var array{data: list<array<string, mixed>>} $body */
        $body = self::bodyOf($this->controller->list(self::user('alice')));

        self::assertSame([[
            'login' => 'bob',
            'can_view' => true,
            'can_edit' => false,
            'see_time_only' => false,
        ]], $body['data']);
    }

    // ------------------------------------------------------------- upsert

    public function testGrantingTwiceEditsTheRowRatherThanAddingASecond(): void
    {
        $this->set('bob', ['can_view' => true, 'can_edit' => true]);
        $this->set('bob', ['can_view' => true, 'can_edit' => false, 'see_time_only' => true]);

        self::assertSame([[
            'cal_login' => 'alice',
            'cal_other_user' => 'bob',
            'cal_can_view' => 1,
            'cal_can_edit' => 0,
            'cal_see_time_only' => 'Y',
        ]], $this->storedRows());
    }

    public function testRightsCanBeTakenAwayAgain(): void
    {
        $this->set('bob', ['can_view' => true, 'can_edit' => true]);
        $this->set('bob', []);

        $rows = $this->storedRows();
        self::assertSame(0, $rows[0]['cal_can_view']);
        self::assertSame(0, $rows[0]['cal_can_edit']);
        self::assertSame('N', $rows[0]['cal_see_time_only']);
    }

    public function testARevokedPermissionReadsBackAsRevoked(): void
    {
        // Both the listing and the reply decide the booleans with `> 0`. Were
        // that ever `>= 0`, every row would report view and edit as granted --
        // including the ones whose rights have just been taken away, which is
        // the only record that they were.
        $this->set('bob', ['can_view' => true, 'can_edit' => true]);
        $reply = $this->set('bob', ['can_view' => false, 'can_edit' => false]);

        /** @var array{data: array<string, mixed>} $replyBody */
        $replyBody = self::bodyOf($reply);
        self::assertFalse($replyBody['data']['can_view']);
        self::assertFalse($replyBody['data']['can_edit']);

        /** @var array{data: list<array<string, mixed>>} $listing */
        $listing = self::bodyOf($this->controller->list(self::user('alice')));
        self::assertFalse($listing['data'][0]['can_view']);
        self::assertFalse($listing['data'][0]['can_edit']);
    }

    // --------------------------------------------------- what is stored

    /** @return iterable<string, array{mixed, int}> */
    public static function flagValues(): iterable
    {
        // The flags are INT NOT NULL standing in for booleans, and the reader
        // asks whether the column is greater than zero -- so a value that is
        // neither 1 nor 0 would read back plausibly while sitting wrong in the
        // table.
        yield 'true' => [true, 1];
        yield 'false' => [false, 0];
        yield 'absent' => [null, 0];
    }

    #[DataProvider('flagValues')]
    public function testAFlagIsStoredAsOneOrZeroAndNothingElse(mixed $given, int $stored): void
    {
        $body = $given === null ? [] : ['can_view' => $given, 'can_edit' => $given];
        $this->set('bob', $body);

        $rows = $this->storedRows();
        self::assertSame($stored, $rows[0]['cal_can_view']);
        self::assertSame($stored, $rows[0]['cal_can_edit']);
    }

    public function testTheReplyDescribesWhatWasStored(): void
    {
        $response = $this->set('bob', ['can_view' => true, 'see_time_only' => true]);

        /** @var array{data: array<string, mixed>} $body */
        $body = self::bodyOf($response);

        self::assertSame([
            'login' => 'bob',
            'can_view' => true,
            'can_edit' => false,
            'see_time_only' => true,
        ], $body['data']);
    }
}
