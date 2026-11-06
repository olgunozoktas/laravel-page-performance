# Time findings measured in a CLI without opcache

Created: 2026-11-07 (UTC)
Priority: **P3**
Tags: idea, accuracy
Done: 2026-11-07 — OpcacheState; a WARN line before the table and `opcache_warning` in --json. The labels stay as they are (the line tells how to read the times). Tests: OpcacheWarningTest, both positions.
Status legend: `- [ ]` = to do, `- [x]` = implemented

## Context

- `perf:pages` renders each page in the CLI process. PHP's CLI default is `opcache.enable_cli=0`, so each render of a
  Blade page compiles every compiled view again.
- Measured 2026-11-07 in a Laravel 13 host (a Livewire page with one large component, 157 views, 96 distinct): the
  component took 15.9 ms best without opcache and 6.7 ms best with `php -d opcache.enable_cli=1`. Without opcache the
  page got a `child-heavy` Defect (the rule's floor is 10 ms); with opcache the component is under the floor.
- The report says nothing about opcache, so a reader takes the CLI times as the server's times.

## Items

- [x] Read `ini_get('opcache.enable_cli')` and `function_exists('opcache_get_status')` at the start of a run.
- [x] When opcache is off in the CLI, print one line before the table: the times include view compilation, and the
      command to run with it on (`php -d opcache.enable_cli=1 artisan perf:pages …`).
- [x] Decide (and test both ways): mark the time-based labels (child-heavy, livewire-bound, slow) as "without opcache",
      or keep them and rely on the line.

## Acceptance

- A run without CLI opcache says so once, before the table; a run with it says nothing.
- Tests for both positions, written and run in this package before the PR.

## Tradeoffs

- A size finding (payload-heavy, oversized-html) does not depend on opcache: leave it as it is.
