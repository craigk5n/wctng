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
}
