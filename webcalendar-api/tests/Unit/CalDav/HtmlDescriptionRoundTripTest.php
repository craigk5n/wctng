<?php

declare(strict_types=1);

namespace App\Tests\Unit\CalDav;

use App\Service\DescriptionSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests that HTML descriptions survive the CalDAV round-trip:
 * create event with HTML → export ICS → verify STYLED-DESCRIPTION present.
 */
final class HtmlDescriptionRoundTripTest extends TestCase
{
    public function testHtmlDescriptionProducesStyledDescription(): void
    {
        if (!class_exists(\WebCalendar\Core\Infrastructure\ICal\EventMapper::class)) {
            $this->markTestSkipped('EventMapper not loadable');
        }

        $mapper = new \WebCalendar\Core\Infrastructure\ICal\EventMapper();
        $event = new \WebCalendar\Core\Domain\Entity\Event(
            id: new \WebCalendar\Core\Domain\ValueObject\EventId(1),
            uid: 'test-html@example.com',
            name: 'HTML Event',
            description: '<p>Hello <strong>bold</strong> world</p>',
            location: 'Room A',
            start: new \DateTimeImmutable('2026-04-01 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: \WebCalendar\Core\Domain\ValueObject\EventType::EVENT,
            access: \WebCalendar\Core\Domain\ValueObject\AccessLevel::PUBLIC,
        );

        $vevent = $mapper->toVEvent($event);

        // Use Writer to serialize
        $vcalendar = new \Icalendar\Component\VCalendar();
        $vcalendar->addComponent($vevent);
        $writer = new \Icalendar\Writer\Writer();
        $ics = $writer->write($vcalendar);

        // Should contain STYLED-DESCRIPTION for RFC 9073
        $this->assertStringContainsString('STYLED-DESCRIPTION', $ics);
        // Should contain X-ALT-DESC for Outlook compatibility
        $this->assertStringContainsString('X-ALT-DESC', $ics);
        // Should contain plain DESCRIPTION fallback
        $this->assertStringContainsString('DESCRIPTION', $ics);
        // HTML should be in the output
        $this->assertStringContainsString('<strong>bold</strong>', $ics);
    }

    public function testPlainTextDescriptionNoStyledDescription(): void
    {
        if (!class_exists(\WebCalendar\Core\Infrastructure\ICal\EventMapper::class)) {
            $this->markTestSkipped('EventMapper not loadable');
        }

        $mapper = new \WebCalendar\Core\Infrastructure\ICal\EventMapper();
        $event = new \WebCalendar\Core\Domain\Entity\Event(
            id: new \WebCalendar\Core\Domain\ValueObject\EventId(2),
            uid: 'test-plain@example.com',
            name: 'Plain Event',
            description: 'Just plain text, no HTML',
            location: '',
            start: new \DateTimeImmutable('2026-04-01 14:00:00'),
            duration: 30,
            createdBy: 'admin',
            type: \WebCalendar\Core\Domain\ValueObject\EventType::EVENT,
            access: \WebCalendar\Core\Domain\ValueObject\AccessLevel::PUBLIC,
        );

        $vevent = $mapper->toVEvent($event);
        $vcalendar = new \Icalendar\Component\VCalendar();
        $vcalendar->addComponent($vevent);
        $writer = new \Icalendar\Writer\Writer();
        $ics = $writer->write($vcalendar);

        // Plain text should NOT produce STYLED-DESCRIPTION
        $this->assertStringNotContainsString('STYLED-DESCRIPTION', $ics);
        $this->assertStringNotContainsString('X-ALT-DESC', $ics);
        // But should have DESCRIPTION
        $this->assertStringContainsString('DESCRIPTION:Just plain text', $ics);
    }

    public function testSanitizedHtmlImportedCleanly(): void
    {
        $sanitizer = new DescriptionSanitizer();

        $xssPayload = '<p>Hello</p><script>alert("xss")</script><strong>safe</strong>';
        $clean = $sanitizer->sanitize($xssPayload);

        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringContainsString('<strong>safe</strong>', $clean);
        $this->assertStringContainsString('<p>Hello</p>', $clean);
    }
}
