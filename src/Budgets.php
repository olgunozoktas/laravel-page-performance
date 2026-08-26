<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * What each page is allowed to cost, in the units that do not move.
 *
 * THE NUMBERS BELONG TO THE HOST, NOT TO THIS PACKAGE. They live in the
 * published `config/page-performance.php`, because a budget is measured against
 * one application's fixture and means nothing anywhere else — the same
 * dashboard costs 34 queries against a real database and 9 against a factory
 * user. This class is only the reader.
 *
 * ONLY DETERMINISTIC COUNTERS BELONG HERE. Query count, avoidable executions,
 * response bytes and component count do not change unless the CODE changes.
 * Milliseconds change on every machine and every run, so there is no
 * millisecond in a budget and `assertNoDurations()` exists to keep it that way:
 * a timing assertion in a parallel suite measures the other workers.
 *
 * THE RATCHET. A number may go DOWN in any commit. Raising one means rewriting
 * `why` to say what got more expensive and moving `measured` — a number you can
 * raise without writing a sentence is a number that gets raised without being
 * read.
 */
final readonly class Budgets
{
    /**
     * @return array{queries: int, duplicates: int, bytes: int|null, components: int, measured: string, why: string}|null
     */
    public static function for(string $route): ?array
    {
        /** @var array<string, array{queries: int, duplicates: int, bytes: int|null, components: int, measured: string, why: string}> $budgets */
        $budgets = config()->array('page-performance.budgets', []);

        return $budgets[$route] ?? null;
    }

    /**
     * @return array<string, array{queries: int, duplicates: int, bytes: int|null, components: int, measured: string, why: string}>
     */
    public static function all(): array
    {
        /** @var array<string, array{queries: int, duplicates: int, bytes: int|null, components: int, measured: string, why: string}> $budgets */
        $budgets = config()->array('page-performance.budgets', []);

        return $budgets;
    }

    /**
     * Pages deliberately not budgeted, and why.
     *
     * A page in neither list is a GAP, and the host's budget test fails on one.
     * An exclusion with no reason becomes permanent the moment whoever wrote it
     * forgets, and a page that quietly stopped being measured reads exactly
     * like a page with no problems.
     *
     * @return array<string, string>
     */
    public static function unbudgeted(): array
    {
        /** @var array<string, string> $unbudgeted */
        $unbudgeted = config()->array('page-performance.unbudgeted', []);

        return $unbudgeted;
    }

    public static function covers(string $route): bool
    {
        return self::for($route) !== null || isset(self::unbudgeted()[$route]);
    }

    /**
     * @return list<string>
     */
    public static function routes(): array
    {
        return array_keys(self::all());
    }

    /**
     * Every budget key that would assert a duration, which must be none.
     *
     * IT READS CONFIG WITHOUT THE DECLARED SHAPE, DELIBERATELY. `all()` types
     * the array as the shape a budget is SUPPOSED to have, and under that type
     * a static analyser can prove this loop never finds anything — which would
     * make the guard permanently silent while looking correct. Config is
     * host-supplied and unvalidated, so the honest type here is the loose one,
     * and the honest type is what lets the check fire.
     *
     * @return list<string>
     */
    public static function durationKeys(): array
    {
        /** @var array<string, array<string, mixed>> $budgets */
        $budgets = config()->array('page-performance.budgets', []);

        $found = [];

        foreach ($budgets as $route => $budget) {
            foreach (array_keys($budget) as $key) {
                if (str_ends_with($key, 'ms') || $key === 'time' || $key === 'seconds') {
                    $found[] = $route.'.'.$key;
                }
            }
        }

        return $found;
    }
}
