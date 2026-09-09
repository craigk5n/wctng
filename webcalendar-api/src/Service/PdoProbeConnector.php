<?php

declare(strict_types=1);

namespace App\Service;

/**
 * ProbeConnector over the real PDO constructor.
 */
final class PdoProbeConnector implements ProbeConnector
{
    #[\Override]
    public function connect(
        string $dsn,
        string $user,
        #[\SensitiveParameter]
        string $password,
        array $options,
    ): \PDO {
        return new \PDO($dsn, $user, $password, $options);
    }
}
