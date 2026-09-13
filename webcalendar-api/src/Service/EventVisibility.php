<?php

declare(strict_types=1);

namespace App\Service;

/**
 * How much of one event a particular reader is allowed to see.
 */
enum EventVisibility
{
    /** Not readable at all. Callers answer as though it were not there. */
    case Hidden;

    /** Readable as a busy block: when, but not what, where or with whom. */
    case TimeOnly;

    case Full;
}
