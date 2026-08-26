<?php

declare(strict_types=1);

use Olgun\PagePerformance\QueryDigest;

/*
 * The digest has to fire on two shapes and stay SILENT on a third that looks
 * almost identical. The silent case is the one worth the test: an eager load
 * emits `… in (?, ?, ?, ?, ?)`, which has more bindings than the N+1 it
 * replaces, so a tool that counted bindings would report the FIX as the defect
 * and get switched off within a week.
 *
 * Every case below was written by injecting the shape and watching exactly one
 * assertion move, per the repository's rule that a detector which reports
 * nothing everywhere cannot be told from a broken one.
 */

/**
 * @return array{sql: string, bindings: string, ms: float, location: string|null}
 */
function query(string $sql, string $bindings = '[]', float $ms = 1.0, ?string $location = null): array
{
    return ['sql' => $sql, 'bindings' => $bindings, 'ms' => $ms, 'location' => $location];
}

it('collapses an IN list to one placeholder so an eager load is one shape', function (): void {
    expect(QueryDigest::normalize('select * from "users" where "id" in (?, ?, ?, ?, ?)'))
        ->toBe('select * from "users" where "id" in (?)');
});

it('leaves a single-placeholder IN alone', function (): void {
    expect(QueryDigest::normalize('select * from "users" where "id" in (?)'))
        ->toBe('select * from "users" where "id" in (?)');
});

it('collapses whitespace so formatting never splits one shape in two', function (): void {
    expect(QueryDigest::normalize("select *\n  from   \"users\""))->toBe('select * from "users"');
});

it('finds a statement run three times with identical bindings', function (): void {
    $digest = QueryDigest::of([
        query('select * from "categories" where "board_id" = ?', '[1]', 2.0, 'BoardHome:88'),
        query('select * from "categories" where "board_id" = ?', '[1]', 1.5),
        query('select * from "categories" where "board_id" = ?', '[1]', 1.5),
    ]);

    expect($digest->repeated())->toHaveCount(1)
        ->and($digest->repeated()[0]['runs'])->toBe(3)
        ->and($digest->repeated()[0]['location'])->toBe('BoardHome:88');
});

it('counts only the executions a fix would delete', function (): void {
    // Three runs of one statement and two of another: the first of each had to
    // happen, so three are avoidable, not five.
    $digest = QueryDigest::of([
        query('select a', '[1]'), query('select a', '[1]'), query('select a', '[1]'),
        query('select b', '[2]'), query('select b', '[2]'),
    ]);

    expect($digest->avoidableExecutions())->toBe(3);
});

it('does not call two different bindings a repeat', function (): void {
    $digest = QueryDigest::of([
        query('select * from "listings" where "id" = ?', '[1]'),
        query('select * from "listings" where "id" = ?', '[2]'),
    ]);

    expect($digest->repeated())->toBeEmpty()
        ->and($digest->avoidableExecutions())->toBe(0);
});

it('reports a loop of single lookups as an N plus one', function (): void {
    $queries = [];

    for ($id = 1; $id <= 6; $id++) {
        $queries[] = query('select * from "listings" where "id" = ?', sprintf('[%d]', $id));
    }

    $digest = QueryDigest::of($queries);

    expect($digest->nPlusOne())->toHaveCount(1)
        ->and($digest->nPlusOne()[0]['distinct_bindings'])->toBe(6);
});

it('STAYS SILENT on the eager load that fixes that N plus one', function (): void {
    // The whole point of normalising first. One query, six bindings, correct.
    $digest = QueryDigest::of([
        query('select * from "listings" where "id" in (?, ?, ?, ?, ?, ?)', '[1,2,3,4,5,6]'),
    ]);

    expect($digest->nPlusOne())->toBeEmpty()
        ->and($digest->repeated())->toBeEmpty()
        ->and($digest->avoidableExecutions())->toBe(0);
});

it('holds below the N plus one threshold, so a handful of lookups is not a finding', function (): void {
    $queries = [];

    for ($id = 1; $id < QueryDigest::N_PLUS_ONE_THRESHOLD; $id++) {
        $queries[] = query('select * from "listings" where "id" = ?', sprintf('[%d]', $id));
    }

    expect($digest = QueryDigest::of($queries))->not->toBeNull()
        ->and($digest->nPlusOne())->toBeEmpty();
});

it('names the slowest query and totals the time', function (): void {
    $digest = QueryDigest::of([
        query('select a', '[]', 1.0),
        query('select b', '[]', 9.5, 'RankedList:41'),
        query('select c', '[]', 2.0),
    ]);

    expect($digest->slowest())->not->toBeNull()
        ->and($digest->slowest()['sql'])->toBe('select b')
        ->and($digest->slowest()['location'])->toBe('RankedList:41')
        ->and($digest->totalMs())->toBe(12.5)
        ->and($digest->count())->toBe(3);
});

it('reports nothing at all for a request that ran no queries', function (): void {
    $digest = QueryDigest::of([]);

    expect($digest->count())->toBe(0)
        ->and($digest->totalMs())->toBe(0.0)
        ->and($digest->repeated())->toBeEmpty()
        ->and($digest->nPlusOne())->toBeEmpty()
        ->and($digest->slowest())->toBeNull();
});

it('NEVER carries a binding value into its output', function (): void {
    // Bindings are the payer's name, their email, a provider reference. This
    // output reaches a terminal, a report file and an agent transcript, so the
    // digest hashes at the boundary and keeps nothing. The sentinel is what
    // proves it, because "we do not print them" is a claim about every future
    // caller and this is a fact about the object.
    $digest = QueryDigest::of([
        query('select * from "users" where "email" = ?', '["payer@example.test"]', 1.0),
        query('select * from "users" where "email" = ?', '["payer@example.test"]', 1.0),
    ]);

    $rendered = json_encode($digest->toArray(), JSON_THROW_ON_ERROR);

    expect($rendered)->not->toContain('payer@example.test')
        ->and($rendered)->not->toContain('example.test')
        // and it still did its job with the value gone
        ->and($digest->avoidableExecutions())->toBe(1);
});

it('groups eager loads of DIFFERENT arity as one shape', function (): void {
    /*
     * This is what normalising actually buys, and the earlier "stays silent"
     * case did not hold it — that one passes with normalisation removed, because
     * a single query cannot repeat whatever you call it. Mutation-tested: delete
     * the IN collapse and THIS case goes red.
     *
     * A chunked loop emits the same statement with a different number of
     * placeholders each pass. Unnormalised those are six shapes of one binding
     * set each and nothing is reported. Normalised they are one shape run six
     * times, which is the finding.
     */
    $queries = [];

    for ($n = 1; $n <= 6; $n++) {
        $placeholders = implode(', ', array_fill(0, $n, '?'));
        $queries[] = query(sprintf('select * from "listings" where "id" in (%s)', $placeholders), sprintf('[set-%d]', $n));
    }

    expect($digest = QueryDigest::of($queries))->not->toBeNull()
        ->and($digest->nPlusOne())->toHaveCount(1)
        ->and($digest->nPlusOne()[0]['sql'])->toBe('select * from "listings" where "id" in (?)')
        ->and($digest->nPlusOne()[0]['distinct_bindings'])->toBe(6);
});
