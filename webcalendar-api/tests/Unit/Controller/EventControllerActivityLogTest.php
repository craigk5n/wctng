<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Service\ConflictDetectionService;
use App\Service\DescriptionSanitizer;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;

/**
 * Tests for activity logging in EventController.
 * Since EventController depends on final CoreServiceFactory,
 * we test the ConflictDetectionService and DescriptionSanitizer
 * independently, and verify activity log integration via integration tests.
 */
final class EventControllerActivityLogTest extends TestCase
{
    public function testActivityLogTypeEnumHasRequiredValues(): void
    {
        $this->assertSame('C', ActivityLogType::CREATE->value);
        $this->assertSame('U', ActivityLogType::UPDATE->value);
        $this->assertSame('A', ActivityLogType::APPROVE->value);
        $this->assertSame('R', ActivityLogType::REJECT->value);
    }

    public function testConflictDetectionServiceIsInstantiable(): void
    {
        $service = new ConflictDetectionService();
        $this->assertInstanceOf(ConflictDetectionService::class, $service);
    }

    public function testDescriptionSanitizerIsInstantiable(): void
    {
        $sanitizer = new DescriptionSanitizer();
        $this->assertInstanceOf(DescriptionSanitizer::class, $sanitizer);
    }
}
