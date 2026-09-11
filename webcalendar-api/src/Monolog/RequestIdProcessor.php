<?php

declare(strict_types=1);

namespace App\Monolog;

use App\EventSubscriber\RequestIdSubscriber;
use App\Tenant\TenantContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that injects the current request ID, and the tenant when
 * one is resolved, into every log record.
 *
 * Both are added here rather than at the top of the request because a record
 * is written at some point during it: by then TenantResolverListener has run,
 * which it has not when RequestIdSubscriber logs the request starting.
 */
final class RequestIdProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RequestIdSubscriber $requestIdSubscriber,
        private readonly TenantContext $tenantContext,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = [];

        $requestId = $this->requestIdSubscriber->getRequestId();
        if ($requestId !== null) {
            $extra['request_id'] = $requestId;
        }

        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            $extra['tenant'] = $tenant->slug();
        }

        if ($extra === []) {
            return $record;
        }

        return $record->with(extra: array_merge($record->extra, $extra));
    }
}
