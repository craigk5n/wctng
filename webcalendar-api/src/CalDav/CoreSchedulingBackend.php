<?php

declare(strict_types=1);

namespace App\CalDav;

/**
 * Scheduling support trait for CoreCalendarBackend.
 *
 * Provides inbox/outbox scheduling object storage for CalDAV scheduling
 * (free/busy queries and meeting invitations). Uses in-memory storage
 * since webcalendar-core doesn't have a persistent scheduling queue.
 */
trait CoreSchedulingBackend
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $schedulingObjects = [];

    /**
     * @param string $principalUri
     * @param string $objectUri
     *
     * @return array<string, mixed>|null
     */
    public function getSchedulingObject($principalUri, $objectUri): ?array
    {
        /** @var string $pUri */
        $pUri = $principalUri;
        /** @var string $oUri */
        $oUri = $objectUri;

        $objects = $this->schedulingObjects[$pUri] ?? [];
        foreach ($objects as $obj) {
            if (($obj['uri'] ?? '') === $oUri) {
                return $obj;
            }
        }

        return null;
    }

    /**
     * @param string $principalUri
     *
     * @return list<array<string, mixed>>
     */
    public function getSchedulingObjects($principalUri): array
    {
        /** @var string $pUri */
        $pUri = $principalUri;

        return $this->schedulingObjects[$pUri] ?? [];
    }

    /**
     * @param string $principalUri
     * @param string $objectUri
     */
    public function deleteSchedulingObject($principalUri, $objectUri): void
    {
        /** @var string $pUri */
        $pUri = $principalUri;
        /** @var string $oUri */
        $oUri = $objectUri;

        if (!isset($this->schedulingObjects[$pUri])) {
            return;
        }

        $this->schedulingObjects[$pUri] = array_values(
            array_filter(
                $this->schedulingObjects[$pUri],
                static fn(array $obj): bool => ($obj['uri'] ?? '') !== $oUri,
            ),
        );
    }

    /**
     * @param string $principalUri
     * @param string $objectUri
     * @param string|resource $objectData
     */
    public function createSchedulingObject($principalUri, $objectUri, $objectData): void
    {
        /** @var string $pUri */
        $pUri = $principalUri;
        /** @var string $oUri */
        $oUri = $objectUri;
        /** @var string $data */
        $data = $objectData;

        if (!isset($this->schedulingObjects[$pUri])) {
            $this->schedulingObjects[$pUri] = [];
        }

        $this->schedulingObjects[$pUri][] = [
            'uri' => $oUri,
            'calendardata' => $data,
            'lastmodified' => time(),
            'etag' => '"' . md5($data) . '"',
            'size' => \strlen($data),
        ];
    }
}
