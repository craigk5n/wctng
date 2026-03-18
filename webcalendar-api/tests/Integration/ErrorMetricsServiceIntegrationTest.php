<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\ErrorMetricsService;

final class ErrorMetricsServiceIntegrationTest extends IntegrationTestCase
{
    private ErrorMetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new ErrorMetricsService($this->pdo);
    }

    public function testRecordAndRetrieveErrors(): void
    {
        $this->assertSame(0, $this->metrics->getRecentErrorCount());

        $this->metrics->recordError();
        $this->metrics->recordError();
        $this->metrics->recordError();

        $this->assertSame(3, $this->metrics->getRecentErrorCount());
    }

    public function testMultipleDaysCount(): void
    {
        $this->metrics->recordError();
        $this->assertSame(1, $this->metrics->getRecentErrorCount(1));
        $this->assertSame(1, $this->metrics->getRecentErrorCount(7));
    }

    public function testCleanupDoesNotRemoveRecentMetrics(): void
    {
        $this->metrics->recordError();
        $this->metrics->cleanup();
        $this->assertSame(1, $this->metrics->getRecentErrorCount());
    }
}
