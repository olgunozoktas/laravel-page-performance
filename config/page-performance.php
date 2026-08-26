<?php

declare(strict_types=1);

/*
 * What `perf:pages` measures, how often, and what each page is allowed to cost.
 *
 * Publish with:
 *   php artisan vendor:publish --tag=page-performance-config
 */
return [

    /*
     * Route NAME prefixes to measure. Empty means every parameterless GET
     * route, which is the right default for an application that has not
     * decided yet.
     */
    'include' => [],

    /*
     * Routes to skip, each with the reason. A reason is required rather than
     * encouraged: an exclusion with no reason becomes permanent the moment
     * whoever added it forgets why, and a page that silently stopped being
     * measured reads exactly like a page with no problems.
     */
    'exclude' => [],

    /*
     * Iterations per page. The FIRST is always discarded and reported
     * separately as `cold`, because the first request handled in a PHP process
     * costs a fixed amount — measured at about 90 ms on one real application,
     * where a page that steady-states at 42 ms read 150 ms when it ran first.
     * A sweep without this ranks pages by visit order and calls it cost.
     */
    'warmup' => 1,
    'iterations' => 5,

    /*
     * Ceilings the host's own test asserts. DETERMINISTIC COUNTERS ONLY —
     * never a millisecond, which changes on every machine and every run.
     *
     * Each entry:
     *   'route.name' => [
     *       'queries' => 12, 'duplicates' => 0, 'bytes' => 90_000, 'components' => 2,
     *       'measured' => '2026-08-26',
     *       'why' => 'Why it is that number, and what would justify raising it.',
     *   ],
     *
     * Measure them rather than guessing: `php artisan perf:pages --json`.
     * Use a ceiling with headroom, never an exact match — an exact assertion
     * breaks on every unrelated change and gets raised without being read.
     */
    'budgets' => [],

    /*
     * Pages deliberately not budgeted, and why. `route.name => 'reason'`.
     */
    'unbudgeted' => [],

];
