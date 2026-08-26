---
name: page-performance
description: Use when a Laravel page feels slow, when deciding what to optimise, or before changing anything for performance. Covers running `perf:pages`, reading defects against characteristics, the traps that make naive page measurement lie (cold-process cost, uncompressed byte counts, ratio labels with no floor), which findings are safe to act on, and the two that are dangerous to "fix". Keywords - perf:pages, page latency, slow page, N+1, repeated query, query budget, payload, snapshot bytes, uncacheable, livewire-bound, db-bound, page performance budget.
---

# Measuring what a Laravel page costs

**Measure first. Every time.** This skill exists because reading code and
guessing is wrong often enough to waste days, and the tool it describes was
built after exactly that happened.

```bash
php artisan perf:pages                     # every parameterless GET route
php artisan perf:pages --only=board        # substring filter on the route name
php artisan perf:pages --as=me@example.com # include authenticated pages
php artisan perf:pages --shuffle           # if the ranking changes, it is noise
php artisan perf:pages --open=3            # open finding 3 in your editor
php artisan perf:pages --json
```

---

## The first thing to understand: two tables, and only one should reach zero

**Defects** are wrong and fixable: `repeated-query`, `n-plus-one`,
`query-heavy`, `vendor-bound`, `payload-heavy`, `snapshot-heavy`,
`child-heavy`, `unbudgeted`.

**Characteristics** are where a request spends itself: `db-bound`,
`livewire-bound`, `noisy-measurement`, and `uncacheable` on a page that carries
a per-session token. They are worth printing. **They are not work.**

A reader who tries to drive characteristics to zero either weakens a detector or
refactors something that was not broken. On one real board, every duplicate
query was fixed and five rows remained — none worth acting on.

---

## Do not act on a finding you have not measured the fix for

The rule reads as obvious and is broken constantly. Two worked examples from the
same session, both found by reading code and both wrong:

| The plan | What the measurement said |
|---|---|
| Memoise a navigation tree built twice per request, ~140 route lookups | **0.002 ms saved.** The whole build is 0.07 ms; `Route::has()` is an array lookup. Reverted. |
| Trim a 280 KB home page — 12.5 KB duplicated SVG, 21.9 KB of framework comments | **Page ships as 30 KB.** Repetition is what a compressor removes. The refactor would have risked a visual regression on every page to save ~200 bytes. |

Before you optimise, get a number for the thing you are about to change. If you
cannot get one, say so rather than proceeding on the shape of the code.

---

## The traps this tool handles, which a hand-rolled sweep will not

**The cold-process floor.** The first request handled in a PHP process costs a
fixed amount whatever page it is — measured at about 90 ms on one application,
where a page that steady-states at 42 ms read 150 ms when it ran first. A sweep
without a warmup ranks pages by visit order and presents it as cost. The `cold`
column is that discarded run, kept and shown, never averaged in.

**Milliseconds are a column, never the sort key.** Rows order by avoidable
queries, then database share, then bytes, and only then time.

**Page weight is judged compressed.** 287,831 bytes of HTML arrived as 32,514
over the wire. The `html` and `wire` columns are both printed so the ratio is
visible.

**Ratio labels have an absolute floor.** A 1.8 ms page is not `db-bound`
because 0.9 ms of it was a settings lookup.

**`in (?, ?, ?)` collapses to `in (?)` before anything is counted**, so a
chunked loop emitting different arities is one shape rather than several.

---

## What to do about each defect

| Label | What it means | Where to look |
|---|---|---|
| `repeated-query` | Identical SQL AND bindings ran twice. The second cannot return anything the first did not. | The `where` column. Memoise it, or use `#[Computed]`. Two readers asking the same question is the usual cause. |
| `n-plus-one` | Same statement, ≥5 distinct binding sets. | Eager load. Note `Model::preventLazyLoading()` catches only the lazy form, not a loop that eager-loads per row. |
| `vendor-bound` | Outbound HTTP **inside a render**. | The worst class: a third party's API is as slow as somebody else's afternoon. Move it behind a lazy component or a queue. |
| `payload-heavy` | ≥100 KB **compressed**. | Paginate. Do not chase uncompressed bytes. |
| `snapshot-heavy` | A Livewire snapshot ≥4 KB, sent up AND back on every interaction. | Move the value out of a public property. `#[Locked]` does not remove it from the snapshot. |
| `child-heavy` | One child ≥25% of its parent's render. | The child is named. |
| `query-heavy` / `unbudgeted` | Over budget, or has no budget. | Budget it, or write down why not. |

---

## Two findings that are DANGEROUS to fix

### `uncacheable` on a page carrying a per-session token

The row says which case it is. When it says the page carries a per-session
token, **leave it alone.** Removing `no-store` to put such a page behind a CDN
publishes one visitor's CSRF token, session cookie and Livewire snapshot
checksum to the next visitor.

Livewire sets `no-store` on every page that mounts a component. On a page with
no per-session state that is worth questioning. On a page with a token it is
correct, and the finding says so precisely because the fix is tempting.

### Moving a slow read into a Livewire component

This is the right fix for `vendor-bound`, and it opens a hole unless the
component authorizes itself.

**A Livewire update does not go through the route's middleware.** It goes to
`/livewire/update`, and Livewire re-runs only its persistent list —
`Authenticate`, `Authorize`, `SubstituteBindings` and the Sanctum entries.
Spatie's `permission:`/`role:` middleware is not on it, and neither is any
custom gate. So a component lifted out of a route behind `permission:manage
billing` is reachable by any signed-in user until it asks that question itself.

Authorize inside the component, in `mount()` **and** `render()`. `#[Locked]`
stops the browser changing a value; it does not authorize it. Both are needed
and they answer different questions.

**And test it correctly.** `Livewire::test()` on a `#[Lazy]` component renders
the **placeholder** and stops — no mount, no render, so no authorization runs. A
test written the obvious way asserts 403 and gets 200, and passes while
protecting nothing. Resume the component the way the browser does:

```php
preg_match("/__lazyLoad\('([^']+)'\)/", $component->html(), $m);
$component->call('__lazyLoad', $m[1])->assertForbidden();
```

Then remove the permission check and watch that exact case go red. A guard that
has never failed is not known to work.

---

## The budget, which is how a win is kept

Milliseconds are reported. Only deterministic counters are asserted — query
count, avoidable executions, component count, and bytes against a fixed fixture.

A timing assertion in a parallel suite measures the other workers. One real
suite reported 232, then 478, then 11 failures from identical code.

```php
// config/page-performance.php
'budgets' => [
    'board.home' => [
        'queries' => 20, 'duplicates' => 0, 'bytes' => 199_000, 'components' => 3,
        'measured' => '2026-08-26',
        'why' => 'A ceiling with headroom, not an exact match. Duplicates is the known debt.',
    ],
],
```

`why` and `measured` are required by the host's own test. A number you can raise
without writing a sentence beside it is a number that gets raised without being
read. A budget may fall in any commit; raising one means rewriting the reason.

**Measure the budget against the FIXTURE the gate uses**, not the live database.
The same dashboard costs 34 queries against imported production data and 9
against a factory user. Neither is wrong; a budget copied between them is
meaningless.

---

## Honest limits, so nobody reads silence as absence

- **Per-component Livewire timing is local-only, permanently.** Livewire gates
  its `profile` event on `config('app.debug')`, and production runs with it off.
  Snapshot bytes are the Livewire metric that survives, and they need no
  instrumentation at all.
- **`vendor-bound` sees Laravel's HTTP client only.** A raw curl handle or a
  vendor SDK with its own Guzzle goes unseen. The label firing is proof; its
  silence is not.
- **Parameterised routes are excluded and counted**, because a guessed id
  answers 404 in about 3 ms and would rank as the fastest page.
- **Mutating verbs are never swept.** A sweep that fired a DELETE changed the
  thing it measured.
- **It reads the real local database and refuses to run in production.**
