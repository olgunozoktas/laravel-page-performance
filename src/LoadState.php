<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * How busy the machine is when the measurement starts.
 *
 * WHY THIS EXISTS. A time is only worth reading on a quiet machine. Measured
 * 2026-11-07 in a host: every run of one evening started at a 1-minute load
 * of 22 to 49 on a 12-core Mac, the same page spread 47% to 175% between
 * runs, and two runs reported `child-heavy` that a quiet run would not
 * support. The report said `noisy-measurement` per row, but nothing said
 * that the whole machine was the reason.
 *
 * Resolved from the container, so a test binds the state it needs. The
 * command reads it once, before the first page.
 */
final readonly class LoadState
{
    public function __construct(private ?float $load = null, private ?int $cores = null) {}

    /** The state now, with both numbers fixed: what the command keeps for the whole run. */
    public function measured(): self
    {
        return new self($this->load(), $this->cores());
    }

    /** The 1-minute load average, or null where the system does not say. */
    public function load(): ?float
    {
        if ($this->load !== null) {
            return $this->load;
        }

        $averages = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return is_array($averages) ? $averages[0] : null;
    }

    /** The logical cores, or null where they cannot be read. */
    public function cores(): ?int
    {
        if ($this->cores !== null) {
            return $this->cores;
        }

        if (is_readable('/proc/cpuinfo')) {
            $count = preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo'));

            return $count > 0 ? $count : null;
        }

        $sysctl = PHP_OS_FAMILY === 'Darwin' ? @shell_exec('sysctl -n hw.logicalcpu 2>/dev/null') : null;
        $count = is_string($sysctl) ? (int) trim($sysctl) : 0;

        return $count > 0 ? $count : null;
    }

    /** Is the load over the core count? Unknown numbers are not busy: the report says nothing it cannot measure. */
    public function busy(): bool
    {
        $load = $this->load();
        $cores = $this->cores();

        return $load !== null && $cores !== null && $load > $cores;
    }

    /** One line for the reader when the machine is busy; null when it is not, or when the numbers are unknown. */
    public function warning(): ?string
    {
        if (! $this->busy()) {
            return null;
        }

        return sprintf(
            'the machine is BUSY: a 1-minute load of %.1f on %d cores when the run started, so every time (ms, slow, livewire-bound, child-heavy) is noise. '
            .'Sizes and query counts are exact. For times, run again when `uptime` shows a load under %d.',
            $this->load(),
            $this->cores(),
            $this->cores(),
        );
    }
}
