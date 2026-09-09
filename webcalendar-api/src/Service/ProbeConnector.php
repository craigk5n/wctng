<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Opens the short-lived connection a readiness probe pings over.
 *
 * Exists so the bounds can be tested. ReadinessProbe's whole claim is that it
 * cannot hang -- a one second connect timeout, and a hundred millisecond cap
 * on the query -- and with `new \PDO` written inline none of that was
 * observable: every mutation of the option array produced a connection that
 * still worked, so nothing failed when the bounds were removed.
 */
interface ProbeConnector
{
    /**
     * @param array<int, mixed> $options
     */
    public function connect(
        string $dsn,
        string $user,
        #[\SensitiveParameter]
        string $password,
        array $options,
    ): \PDO;
}
