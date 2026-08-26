<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * One route the sweeper can request, or a stated reason it cannot.
 *
 * A ROUTE IT SKIPS IS STILL A ROW. The alternative — dropping it — makes a
 * report of 26 pages look like complete coverage of 33, and the seven missing
 * ones are exactly the pages most likely to be broken. Every skip carries the
 * reason, and the report prints the count.
 */
final readonly class MeasurableRoute
{
    private function __construct(
        public string $name,
        public string $uri,
        public bool $measurable,
        public string $skipReason,
    ) {}

    public static function measurable(string $name, string $uri): self
    {
        return new self($name, $uri, true, '');
    }

    public static function skipped(string $name, string $uri, string $reason): self
    {
        return new self($name, $uri, false, $reason);
    }

    public function path(): string
    {
        return '/'.ltrim($this->uri, '/');
    }
}
