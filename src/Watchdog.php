<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * Stops one page from hanging the whole sweep.
 *
 * WHY THIS EXISTS. Nothing else here has a time limit. `Kernel::handle()` runs
 * on the same thread as the measurer, PHP's CLI SAPI sets `max_execution_time`
 * to 0, and a `while (true)` in a controller therefore runs until somebody
 * notices and presses Ctrl-C. The sweep prints nothing while it waits, so the
 * failure reads as "the tool hung", not "page 14 has an infinite loop" — and
 * the pages after it are never measured at all.
 *
 * HOW IT WORKS, AND WHY THE FLAG. `pcntl_alarm()` raises SIGALRM after N whole
 * seconds and the async signal handler runs between opcodes, which is enough to
 * break a CPU loop. It is NOT enough on its own: the handler throws, and the
 * kernel CATCHES that throw and renders a 500, exactly as it does for any other
 * exception. The measurer would then record an ordinary 500 row and say nothing
 * about time. So the handler also sets a flag that outlives the request, and
 * {@see fired()} is read after the kernel returns, outside anything that
 * catches.
 *
 * IT FAILS OPEN. `ext-pcntl` is absent on Windows and on some builds. Without
 * it the sweep runs exactly as it did before, and the report says the watchdog
 * is off — a guard that pretends to be armed is worse than one that is not.
 *
 * A BLOCKING SOCKET IS THE HONEST LIMIT. A signal interrupts a blocking read on
 * most systems, but a driver that restarts the call swallows it. This catches a
 * loop reliably and a hung connection usually.
 */
final class Watchdog
{
    private bool $fired = false;

    private bool $armed = false;

    public function __construct(private int $seconds = 15) {}

    public static function available(): bool
    {
        return function_exists('pcntl_alarm')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals');
    }

    /**
     * Start the clock. A non-positive limit means the caller switched it off.
     */
    public function arm(): void
    {
        $this->fired = false;

        if ($this->seconds <= 0 || ! self::available()) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function (): void {
            $this->fired = true;

            // Unwinds the loop. The kernel will catch it and render a 500; the
            // flag above is what survives that and tells the truth afterwards.
            throw new PageTimedOut($this->seconds);
        });

        $this->armed = true;
        pcntl_alarm($this->seconds);
    }

    /**
     * Stop the clock. Safe to call when nothing was armed.
     */
    public function disarm(): void
    {
        if ($this->armed) {
            pcntl_alarm(0);
            $this->armed = false;
        }
    }

    public function fired(): bool
    {
        return $this->fired;
    }

    public function seconds(): int
    {
        return $this->seconds;
    }
}
