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

## Install

```bash
composer require --dev olgunozoktas/laravel-page-performance
php artisan vendor:publish --tag=page-performance-config
```

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
| `html` | Response bytes. |

### The diagnosis vocabulary

Every label has a stated rule, so it can be argued with.

| Label | Rule |
|---|---|
| `repeated-query` | identical SQL **and** bindings ran ≥2× |
| `n-plus-one` | identical normalised SQL, ≥5 distinct binding sets |
| `db-bound` | database time ≥ 50% of the request |
| `livewire-bound` | Livewire ≥ 40% of the request |
| `child-heavy` | one child ≥ 25% of its parent's render |
| `payload-heavy` | response ≥ 150 KB |
| `snapshot-heavy` | snapshot ≥ 4 KB, or ≥ 3% of the page |
| `query-heavy` | over the page's query budget |
| `unbudgeted` | no budget and no stated reason for not having one |
| `ok` | none of the above — **printed with its numbers**, because a healthy row and an unmeasured row must not look the same |

## Findings are a table, and the locations are clickable

Under the summary comes one row per **finding**, not a paragraph per page:

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

**3. `in (?, ?, ?)` collapses to `in (?)` before anything is counted.** A chunked
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

## Tests

```bash
composer test   # pint --test, phpstan level max, pest
```

Every detector is proved able to **fire** and able to **stay quiet** — including
a page with nothing wrong, which must produce exactly `ok`. A detector that
reports nothing everywhere cannot be told from a broken one.

## Licence

MIT.
