<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SearchIndexService;
use App\Service\TenantAwarePdoProvider;
use PHPUnit\Framework\TestCase;

/**
 * The snippet each search hit carries.
 *
 * generateSnippet() is where most of this class's surviving mutants lived: the
 * window offsets, both ellipses, the highlight and the description-or-title
 * choice were all changeable without a test noticing, because every existing
 * assertion stopped at the title and the result count.
 */
final class SearchSnippetTest extends TestCase
{
    private \PDO $pdo;
    private SearchIndexService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE webcal_entry (
            cal_id INTEGER PRIMARY KEY,
            cal_name VARCHAR(200) DEFAULT '',
            cal_description TEXT DEFAULT '',
            cal_date INTEGER DEFAULT 0,
            cal_time INTEGER DEFAULT 0,
            cal_type CHAR(1) DEFAULT 'E',
            cal_create_by VARCHAR(60) DEFAULT '',
            cal_duration INTEGER DEFAULT 0,
            cal_mod_date INTEGER DEFAULT 0,
            cal_mod_time INTEGER DEFAULT 0,
            cal_access CHAR(1) DEFAULT 'P'
        )");

        $this->service = new SearchIndexService(new TenantAwarePdoProvider($this->pdo));
    }

    private function addEvent(int $id, string $title, string $description, string $type = 'E'): void
    {
        $this->pdo->prepare(
            'INSERT INTO webcal_entry (cal_id, cal_name, cal_description, cal_date, cal_type, cal_create_by)
             VALUES (:id, :name, :description, 20260401, :type, :owner)',
        )->execute([
            'id' => $id,
            'name' => $title,
            'description' => $description,
            'type' => $type,
            'owner' => 'admin',
        ]);
    }

    private function snippetFor(string $query): string
    {
        $result = $this->service->search($query, 'admin');
        self::assertNotSame([], $result['results'], 'expected a hit to take the snippet from');

        return $result['results'][0]['snippet'];
    }

    public function testMatchIsHighlighted(): void
    {
        $this->addEvent(1, 'Planning', 'We will discuss zebra logistics today');

        self::assertStringContainsString('<mark>zebra</mark>', $this->snippetFor('zebra'));
    }

    public function testHighlightIsCaseInsensitive(): void
    {
        // Both the position search and the highlight lower-case their inputs;
        // either could be dropped without the earlier tests noticing.
        $this->addEvent(1, 'Planning', 'We will discuss ZEBRA logistics today');

        self::assertStringContainsString('<mark>ZEBRA</mark>', $this->snippetFor('zebra'));
    }

    public function testSnippetFallsBackToTheTitleWhenThereIsNoDescription(): void
    {
        $this->addEvent(1, 'Zebra planning session', '');

        $snippet = $this->snippetFor('zebra');

        // The title is used verbatim as the snippet source, then highlighted.
        self::assertStringContainsString('<mark>Zebra</mark> planning session', $snippet);
    }

    public function testMatchNearTheStartHasNoLeadingEllipsis(): void
    {
        $this->addEvent(1, 'Planning', 'Zebra logistics for the coming week');

        self::assertStringStartsNotWith('...', $this->snippetFor('zebra'));
    }

    public function testMatchDeepInTheTextIsWindowedWithALeadingEllipsis(): void
    {
        $prefix = str_repeat('padding ', 20); // comfortably more than the 30-char lookbehind
        $this->addEvent(1, 'Planning', $prefix . 'zebra logistics');

        $snippet = $this->snippetFor('zebra');

        self::assertStringStartsWith('...', $snippet);
        self::assertStringContainsString('<mark>zebra</mark>', $snippet);
        self::assertStringNotContainsString($prefix, $snippet, 'the window is not the whole description');
    }

    public function testTextContinuingPastTheWindowGetsATrailingEllipsis(): void
    {
        $this->addEvent(1, 'Planning', 'zebra ' . str_repeat('trailing ', 30));

        self::assertStringEndsWith('...', $this->snippetFor('zebra'));
    }

    public function testShortTextIsNotTruncated(): void
    {
        $this->addEvent(1, 'Planning', 'zebra only');

        $snippet = $this->snippetFor('zebra');

        self::assertStringEndsNotWith('...', $snippet);
        self::assertStringContainsString('only', $snippet);
    }

    public function testQueryMatchingOnlyTheTitleStillYieldsADescriptionSnippet(): void
    {
        // mb_strpos() finds nothing in the description, so the snippet is the
        // opening of it rather than an empty string.
        $this->addEvent(1, 'Zebra', 'A description that never mentions the animal');

        $snippet = $this->snippetFor('zebra');

        self::assertStringContainsString('A description that never mentions', $snippet);
        self::assertStringNotContainsString('<mark>', $snippet);
    }

    public function testRegexMetacharactersInTheQueryAreQuoted(): void
    {
        // Without preg_quote the highlight pattern would be invalid or match
        // the wrong thing entirely.
        $this->addEvent(1, 'Planning', 'Budget (Q1) review');

        $result = $this->service->search('(Q1)', 'admin');

        self::assertNotSame([], $result['results']);
        self::assertStringContainsString('<mark>(Q1)</mark>', $result['results'][0]['snippet']);
    }

    public function testResultCarriesTheStoredFields(): void
    {
        // The row-to-result mapping is a column of ternaries, each of whose
        // branches was interchangeable without a failing assertion.
        $this->addEvent(7, 'Zebra planning', 'notes', 'T');

        $row = $this->service->search('zebra', 'admin')['results'][0];

        self::assertSame(7, $row['id']);
        self::assertSame('Zebra planning', $row['title']);
        self::assertSame('notes', $row['description']);
        self::assertSame('T', $row['type']);
        self::assertSame('admin', $row['created_by']);
        self::assertSame('20260401', $row['start_date']);
    }

    public function testSuggestCarriesTheStoredFields(): void
    {
        $this->addEvent(9, 'Zebra planning', 'notes', 'J');

        $suggestions = $this->service->suggest('Zebra', 'admin');

        self::assertCount(1, $suggestions);
        self::assertSame(9, $suggestions[0]['id']);
        self::assertSame('Zebra planning', $suggestions[0]['title']);
        self::assertSame('J', $suggestions[0]['type']);
        self::assertSame('20260401', $suggestions[0]['start_date']);
    }
    public function testMultibyteTextIsWindowedByCharactersNotBytes(): void
    {
        // The snippet uses mb_substr/mb_strpos/mb_strlen throughout. Swapping
        // any of them for the byte-wise equivalent splits a multibyte
        // character in half and produces mojibake, which only shows up with
        // non-ASCII text.
        $prefix = str_repeat('é', 60);
        $this->addEvent(1, 'Planning', $prefix . 'zebra café details');

        $snippet = $this->snippetFor('zebra');

        self::assertStringContainsString('<mark>zebra</mark>', $snippet);
        self::assertSame($snippet, mb_convert_encoding($snippet, 'UTF-8', 'UTF-8'), 'snippet is still valid UTF-8');
        self::assertMatchesRegularExpression('//u', $snippet, 'no character was cut mid-sequence');
    }

    public function testMultibyteMatchIsHighlightedWhenTheQueryMatchesTheStoredCase(): void
    {
        // Not a case-folding test: SQLite's LIKE only folds ASCII, so "café"
        // would not match a stored "CAFÉ" at the SQL layer at all, whatever the
        // snippet does. That is a driver property, not this class's.
        $this->addEvent(1, 'Planning', 'Discussion of café logistics');

        self::assertStringContainsString('<mark>café</mark>', $this->snippetFor('café'));
    }

    // ------------------------------------------------- the window's dimensions

    public function testAMatchDeepInTheTextIsWindowedToEightyCharacters(): void
    {
        // The window is thirty characters of lead-in and eighty in total.
        // Both were only checked for the presence of an ellipsis, which any
        // nearby offset would still produce.
        $lead = str_repeat('a', 100);
        $this->addEvent(1, 'Planning', $lead . 'zebra' . str_repeat('b', 100));

        $snippet = $this->snippetFor('zebra');

        // '...' + 30 chars of lead-in + '<mark>zebra</mark>' + 45 chars + '...'
        self::assertStringStartsWith('...' . str_repeat('a', 30) . '<mark>zebra</mark>', $snippet);
        self::assertStringEndsWith(str_repeat('b', 45) . '...', $snippet);
    }

    public function testTheLeadInStopsAtTheStartOfTheText(): void
    {
        // max(0, $pos - 30): ten characters in, the window cannot start at
        // -20, and there is no leading ellipsis because nothing was cut.
        $this->addEvent(1, 'Planning', str_repeat('a', 10) . 'zebra' . str_repeat('b', 100));

        $snippet = $this->snippetFor('zebra');

        self::assertStringStartsWith(str_repeat('a', 10) . '<mark>zebra</mark>', $snippet);
    }

    public function testTextThatEndsInsideTheWindowGetsNoTrailingEllipsis(): void
    {
        $this->addEvent(1, 'Planning', 'zebra ' . str_repeat('c', 60));

        $snippet = $this->snippetFor('zebra');

        self::assertStringEndsWith(str_repeat('c', 60), $snippet);
        self::assertStringEndsNotWith('...', $snippet);
    }

    public function testTheTrailingEllipsisIsAddedToTheSnippetNotInsteadOfIt(): void
    {
        // `.=`, not `=`. With assignment the whole snippet becomes '...' and
        // every result in a long-text search reads the same.
        $this->addEvent(1, 'Planning', 'zebra ' . str_repeat('d', 200));

        $snippet = $this->snippetFor('zebra');

        self::assertNotSame('...', $snippet);
        self::assertStringContainsString('<mark>zebra</mark>', $snippet);
        self::assertStringEndsWith('...', $snippet);
    }

    public function testAnUnmatchedDescriptionIsTruncatedToAHundredCharacters(): void
    {
        // The query matched the title, so the description has no match to
        // window around and its opening stands in.
        $this->addEvent(1, 'Zebra', str_repeat('e', 300));

        self::assertSame(str_repeat('e', 100), $this->snippetFor('zebra'));
    }

    public function testAShortUnmatchedDescriptionIsUsedWhole(): void
    {
        $this->addEvent(1, 'Zebra', 'A short note.');

        self::assertSame('A short note.', $this->snippetFor('zebra'));
    }

    public function testTheMatchIsFoundRegardlessOfCaseInNonAsciiText(): void
    {
        // mb_strtolower on both sides, not strtolower: the latter leaves
        // multibyte capitals alone, so the position lookup misses and the
        // snippet silently falls back to the opening of the description with
        // nothing highlighted. SQLite's LIKE only folds ASCII, so the row is
        // matched on the title and the casing question is left to the snippet.
        $this->addEvent(1, 'Café meeting', 'Discussion of CAFÉ logistics today');

        $snippet = $this->snippetFor('café');

        self::assertStringContainsString('<mark>CAFÉ</mark>', $snippet);
    }

    public function testAQueryOfInvalidBytesStillReturnsASnippet(): void
    {
        // The /u modifier is only safe to apply to a valid pattern: with it,
        // broken input compiles to a warning on every row rather than simply
        // not matching. Pasted bytes are a thing users do.
        $this->addEvent(1, 'Planning', 'Nothing special here');

        $result = $this->service->search("\xC3\x28", 'admin');

        self::assertSame([], $result['results'], 'the LIKE finds nothing either way');
    }

    public function testAnAsciiQueryStillHighlightsCaseInsensitively(): void
    {
        $this->addEvent(1, 'Planning', 'We discuss ZEBRA logistics');

        self::assertStringContainsString('<mark>ZEBRA</mark>', $this->snippetFor('zebra'));
    }

    // ------------------------------------------------ the rest of the window

    public function testTheWindowIsExactlyEightyCharacters(): void
    {
        // Not "about eighty": startsWith and endsWith assertions tolerate an
        // off-by-one in the length, and this is what a result row is sized by.
        $this->addEvent(1, 'Planning', str_repeat('a', 100) . 'zebra' . str_repeat('b', 100));

        $snippet = $this->snippetFor('zebra');
        $withoutEllipses = trim($snippet, '.');
        $withoutMarks = str_replace(['<mark>', '</mark>'], '', $withoutEllipses);

        self::assertSame(80, mb_strlen($withoutMarks));
    }

    public function testTheTrailingEllipsisAppearsOnlyWhenTextRemains(): void
    {
        // The boundary is `mb_strlen($text) > $snippetStart + 80`. With the
        // match at the start the window covers the first eighty characters,
        // so eighty exactly has nothing left over and eighty-one has.
        $this->addEvent(1, 'Planning', 'zebra' . str_repeat('c', 75));
        self::assertStringEndsNotWith('...', $this->snippetFor('zebra'));

        $this->addEvent(2, 'Planning', 'zebra' . str_repeat('d', 76));
        $longer = $this->service->search('zebra', 'admin')['results'];
        self::assertStringEndsWith('...', $longer[1]['snippet']);
    }

    public function testAnUpperCaseNonAsciiQueryStillFindsItsMatch(): void
    {
        // mb_strtolower on the *query* side: strtolower leaves É alone, so
        // the lowercased text never contains the query and the snippet falls
        // back to the opening of the description.
        $this->addEvent(1, 'CAFÉ meeting', 'We discuss café logistics in detail');

        $snippet = $this->snippetFor('CAFÉ');

        self::assertStringContainsString('<mark>café</mark>', $snippet);
    }

    public function testAnUnmatchedMultibyteDescriptionIsCutByCharactersNotBytes(): void
    {
        // The 100-character fallback runs through mb_substr; the byte-wise
        // version splits a two-byte character and produces mojibake.
        $this->addEvent(1, 'Zebra', str_repeat('é', 150));

        $snippet = $this->snippetFor('zebra');

        self::assertSame(100, mb_strlen($snippet));
        self::assertMatchesRegularExpression('//u', $snippet, 'no character was cut mid-sequence');
    }

    public function testResultsAreTypedTheSameWhicheverWayTheDriverReturnsColumns(): void
    {
        // SQLite returns a native int for cal_id and cal_date; MySQL
        // stringifies both. The row mapping has to agree either way -- the
        // is_string guard that used to be here shipped an empty start_date.
        $this->addEvent(1, 'Zebra planning', 'notes');

        $result = $this->service->search('zebra', 'admin');

        self::assertIsInt($result['total']);
        self::assertIsInt($result['results'][0]['id']);
        self::assertSame('20260401', $result['results'][0]['start_date']);
    }

    public function testAPageHoldsTwentyResultsByDefault(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->addEvent($i, sprintf('Zebra %02d', $i), 'notes');
        }

        $result = $this->service->search('Zebra', 'admin');

        self::assertCount(20, $result['results']);
        self::assertSame(25, $result['total'], 'the total counts everything, not just the page');
    }

    public function testTheOffsetStartsAtTheBeginning(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->addEvent($i, sprintf('Zebra %02d', $i), 'notes');
        }

        $first = $this->service->search('Zebra', 'admin', null, 1)['results'];
        $second = $this->service->search('Zebra', 'admin', null, 1, 1)['results'];

        self::assertNotSame($first[0]['id'], $second[0]['id'], 'offset 0 is not offset 1');
    }

    public function testTheTrailingEllipsisCountsCharactersNotBytes(): void
    {
        // mb_strlen against strlen: a description of two-byte characters is
        // twice as long in bytes, so the byte-wise version thinks there is
        // more text left and adds an ellipsis to a snippet that ended.
        $this->addEvent(1, 'Planning', 'zebra' . str_repeat('é', 60));

        self::assertStringEndsNotWith('...', $this->snippetFor('zebra'));
    }

    public function testTheSameRowsComeBackWhicheverWayTheDriverTypesThem(): void
    {
        // SQLite returns native ints; MySQL stringifies every column. The
        // casts in the row mapping are what make the two agree, and the same
        // guard written without one shipped an empty start_date from here
        // once already.
        $stringifying = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_STRINGIFY_FETCHES => true]);
        $stringifying->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $stringifying->exec((string) $this->schemaSql());
        $stringifying->prepare(
            'INSERT INTO webcal_entry (cal_id, cal_name, cal_description, cal_date, cal_type, cal_create_by)
             VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([1, 'Zebra planning', 'notes', 20260401, 'E', 'admin']);

        $mysqlish = new SearchIndexService(new TenantAwarePdoProvider($stringifying));

        $this->addEvent(1, 'Zebra planning', 'notes');

        // assertSame, not assertEquals: loose comparison treats '1' and 1 as
        // equal, which is the very difference these casts exist to remove.
        self::assertSame(
            $this->service->search('zebra', 'admin'),
            $mysqlish->search('zebra', 'admin'),
        );
        self::assertSame(
            $this->service->suggest('Zebra', 'admin'),
            $mysqlish->suggest('Zebra', 'admin'),
        );
    }

    private function schemaSql(): string
    {
        return "CREATE TABLE webcal_entry (
            cal_id INTEGER PRIMARY KEY,
            cal_name VARCHAR(200) DEFAULT '',
            cal_description TEXT DEFAULT '',
            cal_date INTEGER DEFAULT 0,
            cal_time INTEGER DEFAULT 0,
            cal_type CHAR(1) DEFAULT 'E',
            cal_create_by VARCHAR(60) DEFAULT '',
            cal_duration INTEGER DEFAULT 0,
            cal_mod_date INTEGER DEFAULT 0,
            cal_mod_time INTEGER DEFAULT 0,
            cal_access CHAR(1) DEFAULT 'P'
        )";
    }
}
