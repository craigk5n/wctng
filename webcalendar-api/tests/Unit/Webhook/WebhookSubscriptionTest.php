<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Webhook\WebhookSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The subscription value object.
 *
 * Its event list is a comma-separated string typed by whoever registered the
 * webhook, and everything the dispatcher does hangs off parsing it: a
 * subscription that fails to match its own events is a webhook that silently
 * never fires. That parsing had no test of its own -- the repository tests
 * call subscribesTo() on values they wrote themselves, which never exercises
 * the untidy shapes a person actually types.
 */
final class WebhookSubscriptionTest extends TestCase
{
    private static function withEvents(string $events): WebhookSubscription
    {
        return new WebhookSubscription(1, 'https://hooks.example.com/hook', $events, 'secret');
    }

    // ------------------------------------------------- parsing the event list

    /** @return iterable<string, array{string, list<string>}> */
    public static function eventStrings(): iterable
    {
        // "event.created, event.updated" is what a person types; without the
        // trim the second one carries a leading space and never matches,
        // so half the subscription quietly stops firing.
        yield 'a single event' => ['event.created', ['event.created']];
        yield 'two, packed' => ['event.created,event.updated', ['event.created', 'event.updated']];
        yield 'two, spaced' => ['event.created, event.updated', ['event.created', 'event.updated']];
        yield 'padded throughout' => ['  event.created , event.updated  ', ['event.created', 'event.updated']];
        yield 'a trailing comma' => ['event.created,', ['event.created']];
        yield 'a doubled comma' => ['event.created,,event.updated', ['event.created', 'event.updated']];
        yield 'nothing but commas' => [',,,', []];
        yield 'empty' => ['', []];
        yield 'whitespace only' => ['   ', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('eventStrings')]
    public function testTheEventStringIsParsedIntoATidyList(string $events, array $expected): void
    {
        $list = self::withEvents($events)->eventList();

        self::assertSame($expected, $list);
        self::assertSame(array_values($list), $list, 'a list, not a gapped array');
    }

    public function testDroppedEntriesDoNotLeaveHolesInTheKeys(): void
    {
        // array_filter preserves keys, so without the reindex a list with a
        // blank in the middle comes back keyed 0, 2 -- which json_encode
        // renders as an object rather than an array.
        $list = self::withEvents('event.created,,event.updated')->eventList();

        self::assertSame([0, 1], array_keys($list));
    }

    // ------------------------------------------------------------- matching

    /** @return iterable<string, array{string, string, bool}> */
    public static function eventMatches(): iterable
    {
        yield 'exact' => ['event.created,event.deleted', 'event.created', true];
        yield 'the second one' => ['event.created,event.deleted', 'event.deleted', true];
        yield 'not subscribed' => ['event.created,event.deleted', 'event.updated', false];
        yield 'spaced entry still matches' => ['event.created, event.updated', 'event.updated', true];
        yield 'a wildcard takes everything' => ['*', 'anything.at.all', true];
        yield 'no events matches nothing' => ['', 'event.created', false];
        yield 'matching is exact, not a prefix' => ['event.created', 'event.created.extra', false];
        yield 'and not a suffix either' => ['event.created', 'my.event.created', false];
        // The wildcard is a whole-string comparison, not an entry in the list.
        // An admin who writes "event.created,*" expecting "these plus
        // everything" gets neither: the star is matched as a literal event
        // name, so only event.created (and an event actually called "*")
        // fires. Recorded because it is surprising, not because it is right.
        yield 'a wildcard among others matches neither' => ['event.created,*', 'anything', false];
        yield 'but the others still match' => ['event.created,*', 'event.created', true];
        yield 'and the star matches itself' => ['event.created,*', '*', true];
    }

    #[DataProvider('eventMatches')]
    public function testSubscribesToMatchesOnlyWhatItShould(string $events, string $event, bool $expected): void
    {
        self::assertSame($expected, self::withEvents($events)->subscribesTo($event));
    }

    public function testTheWildcardIsTheWholeStringNotAnEntry(): void
    {
        // `$this->events === '*'` compares the raw string, so a subscription
        // listing "*" alongside real events matches everything through the
        // in_array branch rather than the wildcard one -- and one that lists
        // "* " with a space does not match everything at all.
        self::assertTrue(self::withEvents('*')->subscribesTo('whatever'));
        self::assertFalse(self::withEvents('* ')->subscribesTo('whatever'));
        self::assertTrue(self::withEvents('* ')->subscribesTo('*'), 'trimmed, it is a literal entry');
    }

    // -------------------------------------------------------------- the rest

    public function testASubscriptionIsEnabledUnlessSaidOtherwise(): void
    {
        // The default is what every caller that omits the flag relies on; a
        // webhook registered as disabled by default never fires.
        self::assertTrue(self::withEvents('event.created')->isEnabled());
        self::assertFalse(
            (new WebhookSubscription(1, 'https://hooks.example.com/hook', 'event.created', 'secret', false))->isEnabled(),
        );
    }

    public function testToArrayCarriesWhatAnAdminSeesAndNothingElse(): void
    {
        // The secret signs every delivery. It is a constructor parameter
        // marked sensitive, and it must not travel in the array the admin API
        // renders.
        $subscription = new WebhookSubscription(7, 'https://hooks.example.com/hook', 'event.created', 'sign-me', false);

        self::assertSame(
            [
                'id' => 7,
                'url' => 'https://hooks.example.com/hook',
                'events' => 'event.created',
                'enabled' => false,
            ],
            $subscription->toArray(),
        );
        self::assertStringNotContainsString(
            'sign-me',
            json_encode($subscription->toArray(), \JSON_THROW_ON_ERROR),
        );
    }
}
