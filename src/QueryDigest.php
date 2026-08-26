<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * Every query one request ran, reduced to the two findings worth acting on.
 *
 * WHAT NORMALISING BUYS, stated accurately because the first version of this
 * docblock got it wrong and a mutation test caught it. Collapsing every
 * `in (?, ?, …)` to `in (?)` does NOT prevent a false positive on a single eager
 * load — one query cannot repeat, whatever you call its shape. What it does is
 * GROUP statements that differ only in how many placeholders they happened to
 * carry. A chunked or paginated loop emits `in (?)`, then `in (?, ?)`, then
 * `in (?, ?, ?)`; unnormalised those are three separate shapes of one binding
 * set each and the loop is reported as nothing at all. Normalised they are one
 * shape run three times, which is the finding.
 *
 * The claim was checked the way this repository requires: delete the collapse,
 * and `it groups eager loads of DIFFERENT arity as one shape` goes red. The
 * earlier "stays silent" case does NOT go red — it was held by there being one
 * query, not by the normalisation. Both cases are kept; only one of them holds
 * this line.
 *
 * TWO DISTINCT FINDINGS, DELIBERATELY NOT MERGED:
 *
 *   REPEATED  the same statement AND the same bindings, more than once. Always a
 *             defect: the second execution cannot return anything the first did
 *             not. Fix with a memo or `#[Computed]`.
 *   N+1       the same statement with many DIFFERENT bindings. Usually a loop
 *             that should have been one `whereIn`. Not always wrong — a handful
 *             of lookups can be cheaper than a join — which is why the threshold
 *             is 5 and not 2.
 *
 * `Model::preventLazyLoading()` is on outside production and already throws on
 * the LAZY form. It says nothing about a loop that eager-loads once per row, and
 * that is the shape this catches.
 *
 * IT NEVER HOLDS A BINDING VALUE. Bindings are the payer's name, their email,
 * a provider reference — every class `.claude/skills/board-privacy` marks as
 * never-public — and this digest's output reaches a terminal, a report file and
 * an agent transcript. Grouping only needs to know whether two binding sets are
 * the SAME, never what they were, so `of()` hashes them at the boundary and the
 * raw values are never stored. Nothing downstream can leak what was never kept.
 *
 * ONE RESIDUAL, STATED RATHER THAN HIDDEN: a statement built by string
 * concatenation instead of a binding carries its value inside `sql`, and this
 * prints `sql`. Laravel's own builder always parameterises; a hand-written
 * `DB::select` with an interpolated value would not.
 */
final readonly class QueryDigest
{
    /** Distinct binding sets before a repeated statement is called an N+1. */
    public const int N_PLUS_ONE_THRESHOLD = 5;

    /**
     * @param  list<array{sql: string, bindings: string, ms: float, location: string|null}>  $queries
     */
    private function __construct(private array $queries) {}

    /**
     * @param  list<array{sql: string, bindings: string, ms: float, location: string|null}>  $queries
     */
    public static function of(array $queries): self
    {
        return new self(array_map(
            static fn (array $query): array => [...$query, 'bindings' => self::fingerprint($query['bindings'])],
            $queries,
        ));
    }

    /**
     * A binding set reduced to an identity with no content.
     *
     * Short on purpose: this is only ever compared to another fingerprint, never
     * reversed and never shown, so eight hex characters distinguish binding sets
     * within one request without carrying a byte of what they held.
     */
    private static function fingerprint(string $bindings): string
    {
        return substr(hash('xxh128', $bindings), 0, 8);
    }

    /**
     * Collapse a statement to its shape.
     *
     * `in (?, ?, ?)` becomes `in (?)` so an eager load and a single lookup are
     * one shape, and runs of whitespace become one space so formatting never
     * splits a shape in two.
     */
    public static function normalize(string $sql): string
    {
        $shape = (string) preg_replace('/\bin\s*\(\s*\?(?:\s*,\s*\?)+\s*\)/i', 'in (?)', $sql);

        return trim((string) preg_replace('/\s+/', ' ', $shape));
    }

    public function count(): int
    {
        return count($this->queries);
    }

    public function totalMs(): float
    {
        return array_sum(array_column($this->queries, 'ms'));
    }

    /**
     * Statements run more than once with identical bindings, worst first.
     *
     * @return list<array{sql: string, runs: int, location: string|null}>
     */
    public function repeated(): array
    {
        $groups = [];

        foreach ($this->queries as $query) {
            $key = self::normalize($query['sql']).'|'.$query['bindings'];

            $groups[$key] ??= ['sql' => self::normalize($query['sql']), 'runs' => 0, 'location' => $query['location']];
            $groups[$key]['runs']++;
        }

        $repeated = array_values(array_filter($groups, static fn (array $group): bool => $group['runs'] > 1));

        usort($repeated, static fn (array $a, array $b): int => $b['runs'] <=> $a['runs']);

        return $repeated;
    }

    /**
     * How many executions could be deleted outright.
     *
     * Three runs of one statement and two of another is THREE avoidable
     * executions, not five — the first of each had to happen. This is the number
     * a budget asserts on, because it is the number a fix removes.
     */
    public function avoidableExecutions(): int
    {
        return array_sum(array_map(static fn (array $group): int => $group['runs'] - 1, $this->repeated()));
    }

    /**
     * Statements run with many different bindings — the loop-shaped defect.
     *
     * @return list<array{sql: string, runs: int, distinct_bindings: int, location: string|null}>
     */
    public function nPlusOne(): array
    {
        $groups = [];

        foreach ($this->queries as $query) {
            $shape = self::normalize($query['sql']);

            $groups[$shape] ??= ['sql' => $shape, 'runs' => 0, 'bindings' => [], 'location' => $query['location']];
            $groups[$shape]['runs']++;
            $groups[$shape]['bindings'][$query['bindings']] = true;
        }

        $found = [];

        foreach ($groups as $group) {
            $distinct = count($group['bindings']);

            if ($distinct >= self::N_PLUS_ONE_THRESHOLD) {
                $found[] = ['sql' => $group['sql'], 'runs' => $group['runs'], 'distinct_bindings' => $distinct, 'location' => $group['location']];
            }
        }

        usort($found, static fn (array $a, array $b): int => $b['distinct_bindings'] <=> $a['distinct_bindings']);

        return $found;
    }

    /**
     * @return array{sql: string, ms: float, location: string|null}|null
     */
    public function slowest(): ?array
    {
        $slowest = null;

        foreach ($this->queries as $query) {
            if ($slowest === null || $query['ms'] > $slowest['ms']) {
                $slowest = ['sql' => self::normalize($query['sql']), 'ms' => $query['ms'], 'location' => $query['location']];
            }
        }

        return $slowest;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'queries' => $this->count(),
            'total_ms' => round($this->totalMs(), 2),
            'avoidable' => $this->avoidableExecutions(),
            'repeated' => $this->repeated(),
            'n_plus_one' => $this->nPlusOne(),
            'slowest' => $this->slowest(),
        ];
    }
}
