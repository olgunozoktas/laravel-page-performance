<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use RuntimeException;

/**
 * A page did not answer inside the watchdog's limit.
 *
 * This is thrown from a signal handler, so it can surface from ANY line of the
 * page's own code. Never catch it to keep going — {@see Watchdog} exists
 * because the alternative is a sweep that hangs with no output.
 */
final class PageTimedOut extends RuntimeException
{
    public function __construct(public readonly int $seconds)
    {
        parent::__construct(sprintf(
            'The page did not answer within %d s. That is a loop, a sleep, or a blocking call — not slowness.',
            $seconds,
        ));
    }
}
