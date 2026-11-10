# Measure pages whose route takes a parameter

Created: 2026-11-10 (UTC)
Priority: **P3**
Tags: idea, back-end
Need: 46 GET routes of a Laravel 13 host app were "not requestable" because they take a route parameter, and 10 of
them were its main signed-in pages (sites, reports, workspaces): `php artisan perf:pages --json`, the `skipped`
list grouped by reason, 2026-11-10 06:15 (+0300). Those pages got no query count and no Defect check.
Status legend: `- [ ]` = to do, `- [x]` = implemented

## Context

- `RouteCatalogue` skips each route with a parameter (`src/RouteCatalogue.php`, the `skipped(...)` call with "Takes a
  route parameter"). The reason is good: a guessed value answers 404, and a 404 ranks as the fastest page.
- The result: the pages that a signed-in customer uses most (a record's page, its report) are the pages that the
  command never measures. A repeated query or an N+1 there is not found before a merge.
- Today the only way is by hand: `DB::enableQueryLog()` in tinker, and a request through the kernel.

## Items

- [ ] An option that gives a route its parameter values, e.g. `--param=sites.show:site=<value>`, or a config map
      `page-performance.parameters` (`route name => [parameter => value]`).
- [ ] Optionally: a resolver per parameter, e.g. "the first row of the bound model that `--as` can see", off by
      default.
- [ ] A route with values given is measured as any other page; a route without them stays skipped with today's
      reason.
- [ ] A measured page that answers 404 or 403 is reported as "NOT THE PAGE", as the 404 rows are now, never ranked.
- [ ] README: the option, with one example.

## Acceptance

- Tests in this package (`vendor/bin/pest`): a route with given values is measured; one without stays skipped with
  its reason; a value that answers 404 is reported as not the page. Break each guard; name the failing test.
- `php artisan perf:pages --param=<route>:<parameter>=<value>` measures that page in a host app.
- The owner sees the parameter pages in the table with their query counts.

## Tradeoffs

- A value from the config can point at a row that a later seed removes: the 404 rule above makes that visible.
- An automatic resolver reads the database to pick a row; keep it off by default.
