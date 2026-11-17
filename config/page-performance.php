<?php

declare(strict_types=1);

/*
 * What `perf:pages` and `perf:requests` measure, how often, and what each page
 * and named request is allowed to cost.
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
     * Values for the routes that take a parameter. A route with a parameter is
     * skipped until every required parameter has a value here (or from
     * `--param=route.name:parameter=value`, which wins for one run). The
     * package never guesses a value: a guessed value answers 404, and a 404
     * would rank as the fastest page.
     *
     * Each entry: 'posts.show' => ['post' => 'hello-world'].
     *
     * The values point at rows of the local database. A value whose row is
     * gone answers 404, and the report shows that page as NOT THE PAGE.
     */
    'parameters' => [],

    /*
     * Iterations per page. The FIRST is always discarded and reported
     * separately as `cold`, because the first request handled in a PHP process
     * costs a fixed amount — measured at about 90 ms on one real application,
     * where a page that steady-states at 42 ms read 150 ms when it ran first.
     * A sweep without this ranks pages by visit order and calls it cost.
     */
    'warmup' => 1,

    /*
     * The memory floor of one sweep. Every page is measured in this one process, and the views, the query log and the
     * Livewire profile of ~200 pages do not fit PHP's CLI default of 128M (a sweep of 194 pages of a Laravel
     * application ended in "Allowed memory size of 134217728 bytes exhausted" at that default). A higher limit, or
     * -1, is kept; null or '' leaves the limit as it is.
     */
    'memory_limit' => env('PAGE_PERFORMANCE_MEMORY_LIMIT', '1G'),
    'iterations' => 5,

    /*
     * Which editor a clickable `where` link should open.
     *
     * phpstorm · idea · vscode · cursor · sublime · textmate · zed · file
     * Empty disables the links and prints plain text.
     *
     * Links are emitted as OSC 8 escapes and ONLY when the output is a real
     * terminal — piping to a file or a pager would otherwise fill it with
     * control characters and stop a grep matching the paths.
     */
    'editor' => env('PAGE_PERFORMANCE_EDITOR', 'phpstorm'),

    /*
     * Whether to emit clickable-link escapes at all.
     *
     * `auto` checks TERM_PROGRAM against the terminals known to render OSC 8.
     * macOS Terminal.app is NOT one of them — it renders the escape as nothing,
     * so a report that emitted them anyway told the reader a column was
     * clickable when it was not. Use `--open=N` there instead.
     *
     * auto · always · never
     */
    'hyperlinks' => env('PAGE_PERFORMANCE_HYPERLINKS', 'auto'),

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
     * Pages and named requests deliberately not budgeted, and why.
     * `route.name => 'reason'` or `request name => 'reason'`.
     */
    'unbudgeted' => [],

    /*
     * The named requests of `perf:requests`: any method, headers and a JSON
     * body. Each run is in a database transaction that is rolled back, and
     * an outbound HTTP call fails the row ("outbound call") without leaving
     * the process. A budget for a request uses its name as the key.
     *
     * Each entry:
     *   'posts.store' => [
     *       'method' => 'POST',
     *       'path' => '/api/posts',
     *       'headers' => ['Authorization' => 'Bearer {fixture.token}'],
     *       'json' => ['title' => 'Hello', 'author_id' => '{fixture.user_id}'],
     *   ],
     *
     * `Accept: application/json` is added when no Accept header is named, and
     * `Content-Type: application/json` when the entry has a body.
     */
    'requests' => [],

    /*
     * A class that implements Olgun\PagePerformance\RequestFixture, or null.
     * Its setUp() makes the rows the requests need (a user, an API key) in a
     * transaction that is rolled back, and returns the values that a request
     * names as {fixture.key}. A body value that is only the placeholder keeps
     * the type of the value.
     */
    'fixture' => null,

];
