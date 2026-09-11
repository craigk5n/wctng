<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use Psr\Log\AbstractLogger;

/**
 * Captures what was logged so the level, message and context can be read back.
 *
 * In its own file rather than alongside one of the test classes: with
 * classmap-authoritative set, a class PHPUnit has not loaded as a test file
 * itself has to be somewhere the autoloader can find it.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
