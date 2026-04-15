<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\TokenRevocationService;
use App\Security\UserTokenIndex;
use Lexik\Bundle\JWTAuthenticationBundle\Services\BlockedTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TokenRevocationServiceTest extends TestCase
{
    public function testRevokeAllForBlocksEveryLiveTokenAndClearsIndex(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $clock = new MockClock('2026-04-15T12:00:00+00:00');
        $index = new UserTokenIndex($pdo, $clock);

        $exp = $clock->now()->getTimestamp() + 3600;
        $index->record('alice', 'jti-1', $exp);
        $index->record('alice', 'jti-2', $exp);
        $index->record('bob', 'jti-3', $exp);

        $blocked = new SpyBlockedTokenManager();
        $service = new TokenRevocationService($blocked, $index);

        $count = $service->revokeAllFor('alice');

        self::assertSame(2, $count);
        self::assertSame(['jti-1', 'jti-2'], array_map(static fn(array $p) => $p['jti'], $blocked->added));
        self::assertSame([], $index->liveTokensFor('alice'), 'Alice\'s index is wiped after revocation');
        self::assertCount(1, $index->liveTokensFor('bob'), 'Bob\'s tokens untouched');
    }

    public function testRevokeAllForSwallowsIndividualBlocklistFailures(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $clock = new MockClock('2026-04-15T12:00:00+00:00');
        $index = new UserTokenIndex($pdo, $clock);
        $index->record('alice', 'jti-1', $clock->now()->getTimestamp() + 3600);
        $index->record('alice', 'jti-2', $clock->now()->getTimestamp() + 3600);

        $blocked = new SpyBlockedTokenManager(failOnJti: 'jti-1');
        $service = new TokenRevocationService($blocked, $index);

        $count = $service->revokeAllFor('alice');

        self::assertSame(1, $count, 'only the non-failing jti is counted');
        self::assertSame([], $index->liveTokensFor('alice'), 'index is still wiped — we don\'t want zombies');
    }

    public function testRevokeAllForReturnsZeroWhenUserHasNoLiveTokens(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $clock = new MockClock('2026-04-15T12:00:00+00:00');
        $index = new UserTokenIndex($pdo, $clock);

        $service = new TokenRevocationService(new SpyBlockedTokenManager(), $index);

        self::assertSame(0, $service->revokeAllFor('unknown-user'));
    }
}

/**
 * Minimal BlockedTokenManagerInterface spy — captures what `add()` sees
 * so the test can assert the exact payloads handed to Lexik's blocklist
 * without mocking.
 *
 * @internal
 */
final class SpyBlockedTokenManager implements BlockedTokenManagerInterface
{
    /** @var list<array<string, mixed>> */
    public array $added = [];

    public function __construct(
        private readonly ?string $failOnJti = null,
    ) {}

    public function add(array $payload): bool
    {
        if ($this->failOnJti !== null && ($payload['jti'] ?? null) === $this->failOnJti) {
            throw new \RuntimeException('simulated blocklist failure');
        }
        $this->added[] = $payload;

        return true;
    }

    public function has(array $payload): bool
    {
        foreach ($this->added as $entry) {
            if (($entry['jti'] ?? null) === ($payload['jti'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function remove(array $payload): void
    {
        $this->added = array_values(array_filter(
            $this->added,
            static fn(array $entry) => ($entry['jti'] ?? null) !== ($payload['jti'] ?? null),
        ));
    }
}
