<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * Is opcache on in the process that measures?
 *
 * WHY THIS EXISTS. perf:pages renders each page in the CLI, and PHP's CLI
 * default is `opcache.enable_cli=0`. Then every render compiles the compiled
 * views again, and the times include that work. Measured 2026-11-07 in a host:
 * one Livewire component (157 views) took 15.9 ms without opcache and 6.7 ms
 * with it, and only the run without it reported `child-heavy` (the floor is
 * 10 ms). The report said "opcache off" in its Runtime line, and nobody read
 * it as a reason to doubt the times.
 *
 * Resolved from the container, so a test binds the state it needs.
 */
final readonly class OpcacheState
{
    public function __construct(private ?bool $enabled = null) {}

    public function enabled(): bool
    {
        return $this->enabled ?? (function_exists('opcache_get_status') && is_array(@opcache_get_status(false)));
    }

    /** One line for the reader when opcache is off; null when it is on. */
    public function warning(): ?string
    {
        if ($this->enabled()) {
            return null;
        }

        return 'opcache is OFF in this CLI: each render compiles the views again, so every time (ms, slow, livewire-bound, child-heavy) '
            .'can be several times the server\'s. Sizes and query counts are exact. For times, run: php -d opcache.enable_cli=1 artisan perf:pages …';
    }
}
