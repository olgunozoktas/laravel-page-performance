# Laravel Page Performance

**Measure what every page actually costs, and say what to fix.**

`php artisan perf:pages` walks every parameterless GET route, warms the process,
measures N in-process requests, and prints a table ordered worst-first where
every row carries a **diagnosis** rather than a number.

```
+-----------------------------+------+--------+------+------+------+----+-----+------+------+-------------------------------------------+
| page                        | ms   | spread | cold | db   | lw   | q  | dup | snap | html | diagnosis                                 |
+-----------------------------+------+--------+------+------+------+----+-----+------+------+-------------------------------------------+
| board.home                  | 68.5 | 2%     | 71   | 34.9 | 24.4 | 18 | 2   | 727  | 260K | repeated-query · db-bound · payload-heavy |
| board.directory             | 32.6 | 11%    | 39   | 13.5 | 12.5 | 8  | 1   | 277  | 153K | repeated-query · payload-heavy            |
| board.rules                 | 15.5 | 4%     | 16   | 6.9  | 3.0  | 5  | 1   | 273  | 69K  | repeated-query                            |
+-----------------------------+------+--------+------+------+------+----+-----+------+------+-------------------------------------------+

board.home /   repeated-query · db-bound · payload-heavy
    x2   select count(*) as "aggregate" from "listings" where "status" = ? and …
         app/Boards/PublicPages/BoardHome.php:88
```

A table of milliseconds tells you which page is slowest. It does not tell you
what to open. Every finding here ends in a file and a line.

**Those route names are real.** `board.home`, `board.directory` and
`board.rules` are pages of [SeeRanks](https://seeranks.com), the application
this package was written for and is measured against. Every number in this
README — the 150.4 ms cold request, the 287,831 bytes that arrive as 32,514, the
77 findings that group to 8 — came from a sweep of that codebase, not from a
benchmark written to make a point.

## Install

**Not on Packagist yet.** Add the repository, then require it:

```jsonc
// composer.json
"repositories": [
    { "type": "vcs", "url": "https://github.com/olgunozoktas/laravel-page-performance" }
]
```

```bash
composer require --dev olgunozoktas/laravel-page-performance:^0.1
php artisan vendor:publish --tag=page-performance-config
```

Once it is listed on Packagist the `repositories` entry becomes unnecessary and
`composer require --dev olgunozoktas/laravel-page-performance` is enough.

Livewire is **optional**. Without it you get every page-level number; with it you
also get per-component render timing and snapshot payload bytes.

## What it measures

| Column | Meaning |
|---|---|
| `ms` | **Median** of the timed runs. Never the mean — one GC pause ruins a mean and nobody reading it can see that it did. |
| `spread` | How far the runs disagreed. Above ~25% the position is not trustworthy, and the report says so. |
| `cold` | The **discarded** first run. Kept and shown, never averaged in. |
| `db` | Database time. |
| `lw` | Livewire time, children counted once. Empty when Livewire is absent or `app.debug` is false. |
| `q` / `dup` | Queries, and how many executions a fix would delete. |
| `snap` | Livewire snapshot bytes — sent up **and** back on every interaction. |
| `html` | Response bytes, uncompressed. |
| `wire` | **What the visitor downloads.** Estimated gzip size — the number `payload-heavy` judges. |

### The diagnosis vocabulary

Every label has a stated rule, so it can be argued with.

| Label | Rule |
|---|---|
| `repeated-query` | identical SQL **and** bindings ran ≥2× |
| `n-plus-one` | identical normalised SQL, ≥5 distinct binding sets |
| `db-bound` | database time ≥ 50% of the request **and** ≥ 10 ms |
| `livewire-bound` | Livewire ≥ 40% of the request **and** ≥ 10 ms |
| `child-heavy` | one child ≥ 25% of its parent's render |
| `payload-heavy` | response ≥ 100 KB **compressed** |
| `snapshot-heavy` | snapshot ≥ 4 KB, or ≥ 3% of the page |
| `vendor-bound` | outbound HTTP was made **inside the render** |
| `uncacheable` | `no-store` on a page that mounts a component — and it says whether a per-session token makes the page uncacheable **anyway** |
| `query-heavy` | over the page's query budget |
| `unbudgeted` | no budget and no stated reason for not having one |
| `ok` | none of the above — **printed with its numbers**, because a healthy row and an unmeasured row must not look the same |

## Defects and characteristics are separate, and only one of them should reach zero

Under the summary come two tables, and the difference between them is the point.

**Defects** are things that are wrong and that you can fix: a repeated query, an
N+1, an outbound call inside a render, a page over its budget.

**Characteristics** are where a request spends itself — `db-bound`,
`livewire-bound`, a page that cannot be shared-cached because it carries a
per-session token, a row whose spread says the measurement is not trustworthy.
They are worth printing and they are not defects.

Mixing them makes the number unusable. On one real board, every duplicate query
was fixed and the report still showed five "findings", none of which anybody
should act on — and a count that cannot reach zero is a count people stop
reading.

One row per defect, not a paragraph per page:

```
  Findings .............................................. 52, worst page first
+----+----------------------+-------------------+------------------------------------------+---------------------------------+
| #  | page                 | finding           | evidence                                 | where                           |
+----+----------------------+-------------------+------------------------------------------+---------------------------------+
| 1  | board.home           | repeated-query    | x2 select count(*) from "listings" …     | app/Boards/ReadsBoard.php:49    |
| 3  | board.home           | livewire-bound    | 23.7 ms of 40.5 ms in Livewire           | —                               |
| 4  | board.home           | payload-heavy     | 268K of HTML                             | —                               |
| 7  | board.similar-boards | noisy-measurement | 41% spread — position not trustworthy    | —                               |
+----+----------------------+-------------------+------------------------------------------+---------------------------------+
```

The `where` column is an **OSC 8 hyperlink**. Click it and the file opens at the
line.

```php
// config/page-performance.php
'editor' => env('PAGE_PERFORMANCE_EDITOR', 'phpstorm'),
```

`phpstorm` · `idea` · `vscode` · `cursor` · `sublime` · `textmate` · `zed` ·
`file`. An empty value prints plain text, and so does an editor the package does
not recognise — emitting a URL scheme nothing will answer is worse than emitting
none.

**It degrades whenever the output is not a terminal.** Piped into a file or a
pager, those escapes would sit in the output as control characters and stop a
grep matching the very paths the column exists to hand over. Both directions are
tested.

## Three things it gets right that a naive sweep does not

**1. The cold-process trap.** The first request handled in a PHP process costs a
fixed amount regardless of which page it is. Measured on a real application:

| Order | Page | Wall |
|---|---|---|
| 1st in process | `/` | 150.4 ms |
| 1st in process | `/products` | 92.0 ms |
| 3rd–6th | `/` | 41–53 ms |

A sweep without a warmup charges that to whichever page ran first and presents
the result as a ranking by cost. This warms first, discards the cold run, and
shows it in its own column.

**2. Milliseconds are a column, never the sort key.** Rows order by avoidable
queries, then database time, then bytes, and only then the clock. Deterministic
evidence decides the order; time is the tiebreak of last resort.

**3. One defect is one row.** A finding carried by 27 pages is printed once,
with `27 pages` in the page column — not 27 times. A real sweep produced **77**
findings of which 28 were the same table-existence check from the same line;
grouped, the same sweep reports **8**. A report you have to de-duplicate by eye
is a report that gets skimmed.

**4. Ratio labels have an absolute floor.** A 1.8 ms page is not `db-bound`
because 0.9 ms of it was a settings lookup. Below 10 ms a share is arithmetic,
not a finding — and `noisy-measurement` needs both a wide spread and a 15 ms
absolute gap, because 340% of 2.5 ms is jitter.

**5. Page weight is judged COMPRESSED, because that is what a visitor pays.**
Measured on a real board home page: 287,831 bytes of HTML arrive as **32,514**
over the wire, 89% smaller. The things that make server-rendered HTML large —
repeated Tailwind classes, repeated inline SVG, framework comments — are exactly
what a compressor removes. Judging the uncompressed number sends people to
refactor a shared icon component for about two hundred real bytes. Both figures
are reported so the ratio is visible.

**6. `in (?, ?, ?)` collapses to `in (?)` before anything is counted.** A chunked
or paginated loop emits the same statement with a different number of
placeholders each pass. Unnormalised those are separate shapes and the loop is
reported as nothing at all.

## The gate half

Timings are **reported**. Only deterministic counters may be **asserted**:

| Bucket | Gate? |
|---|---|
| query count, avoidable executions, response bytes, component count | yes |
| wall time, database time, memory | **never** |

A timing assertion in a parallel suite measures the other workers. One real
suite reported 232, then 478, then 11 failures across three runs of identical
code. A gate that fails for reasons nobody can act on is one people learn to
ignore.

Budgets live in your published config, measured against **your** fixture:

```php
'budgets' => [
    'board.home' => [
        'queries' => 20, 'duplicates' => 2, 'bytes' => 199_000, 'components' => 3,
        'measured' => '2026-08-26',
        'why' => '17 queries and 155 KB over the seeded board. A ceiling with headroom,
                  not an exact match. Duplicates is 2 and that is the remaining debt.',
    ],
],
```

`why` and `measured` are not decoration. A number you can raise without writing
a sentence beside it is a number that gets raised without being read — which is
how a budget stops being one. The ratchet: a budget may fall in any commit;
raising one means rewriting the reason.

**There is no `--update-baseline`.** A budget a machine can rewrite is not a
budget. Re-measure with `perf:pages --json` and copy the number in by hand.

Assert them in your own test — `Budgets::all()`, `Budgets::covers()` and
`Budgets::durationKeys()` are the reader.

## Two costs most tools miss

**Outbound HTTP inside a render.** A query is as slow as your database; a third
party's API is as slow as somebody else's afternoon. `vendor-bound` names the
host and the count. It sees Laravel's own HTTP client — a raw curl handle or a
vendor SDK with its own Guzzle goes unseen, so the label firing is proof and its
silence is not.

**Pages that no cache may hold.** Livewire's `SupportDisablingBackButtonCache`
sets `Cache-Control: no-store` on **every** page that mounts a component. That is
right behind a login and quietly expensive on a public one: a landing page you
wanted a CDN to hold is uncacheable because it carries a single component.

**The finding names the real blocker, not just the cause — and that distinction
is a security one.** Saying only "Livewire set no-store" invites removing the
header and putting the page behind a CDN. Measured on a real board home page,
that would have served one visitor's CSRF token, session cookie and Livewire
snapshot checksum to the next visitor. So the row says whether the page carries
per-session state, and only a page carrying none is worth questioning.

## Honest limits

- **The per-component phase breakdown is local-only, permanently.** Livewire
  gates its own `profile` event on `config('app.debug')` at every trigger site.
  Production runs `APP_DEBUG=false` and must. Snapshot bytes are the Livewire
  metric that survives production — they are read from the response HTML and
  need no instrumentation at all.
- **It measures your local database**, which is the point: on an empty database
  a listing page renders no rows, and that is a number about software you do not
  ship. It refuses to run against production.
- **It never writes.** GET routes only; mutating verbs are excluded
  structurally, not by a list somebody maintains.
- **Parameterised routes are excluded and counted.** A guessed id answers 404 in
  about 3 ms and would rank as the fastest page in the application.

## Commands

```bash
php artisan perf:pages                     # everything
php artisan perf:pages --only=board        # substring filter on the route name
php artisan perf:pages --repeat=9          # more iterations
php artisan perf:pages --shuffle           # if the ranking changes, it is noise
php artisan perf:pages --as=me@example.com # measure authenticated pages
php artisan perf:pages --json
```

Exit codes: `0` measured · `2` nothing could be measured, which is **not** a
pass · non-zero on bad usage.

## The agent skill

The repository ships a skill at `.claude/skills/page-performance/SKILL.md`. Copy
it into a project so an agent working there knows how to read the output, which
findings are safe to act on, and — the part that matters — the two that are
dangerous to "fix":

```bash
mkdir -p .claude/skills
cp -R vendor/olgunozoktas/laravel-page-performance/.claude/skills/page-performance .claude/skills/
```

It carries the measured lessons rather than the API: that a navigation memo
saved 0.002 ms, that a 280 KB page ships as 30 KB, that removing `no-store` from
a page with a session token publishes one visitor's CSRF token to the next, and
that `Livewire::test()` on a `#[Lazy]` component renders the placeholder — so an
authorization test written the obvious way asserts 403, gets 200, and protects
nothing.

## Tests

```bash
composer test   # pint --test, phpstan level max, pest
```

Every detector is proved able to **fire** and able to **stay quiet** — including
a page with nothing wrong, which must produce exactly `ok`. A detector that
reports nothing everywhere cannot be told from a broken one.

## Where this came from, and what else runs on it

This package exists because one Laravel application needed it. That application
is [**SeeRanks**](https://seeranks.com) — a paid ranking board for developer
products, being rebuilt on Laravel 13 + Livewire 4. It is where the cold-process
trap was measured, where the `no-store` finding was traced to a real CSRF token,
and where a 77-finding sweep turned out to be 8 defects printed 77 times.

Two other things by the same author, both free:

| | |
|---|---|
| [**FindUtils**](https://findutils.com) | Nearly 400 free online tools — converters, formatters, generators, calculators. The work happens in the browser: no account, and files are not uploaded. |
| [**Emoji Favicons**](https://emojifavicons.com) | Turn any emoji into a favicon, with a documented API. One `<link>` tag and a site has an icon. |

Neither is a Laravel application, so neither is measured by this package. They
are here because the same person maintains all four, and because a tool is
easier to trust when you can see what else its author ships.

## Licence

MIT.
