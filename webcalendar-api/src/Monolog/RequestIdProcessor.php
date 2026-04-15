<?php

declare(strict_types=1);

namespace App\Monolog;

use App\EventSubscriber\RequestIdSubscriber;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that injects the current request ID into every log record.
 * This enables correlating all log entries from a single request.
 */
final class RequestIdProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RequestIdSubscriber $requestIdSubscriber,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $requestId = $this->requestIdSubscriber->getRequestId();
        if ($requestId !== null) {
            return $record->with(extra: array_merge($record->extra, ['request_id' => $requestId]));
        }

        return $record;
    }
}
