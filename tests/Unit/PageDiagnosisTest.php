<?php

declare(strict_types=1);

use Olgun\PagePerformance\PageDiagnosis;

/*
 * Every label, one case each, plus the two cases that matter more than the rest:
 * a clean page must produce exactly `ok`, and an unbudgeted page must say so.
 *
 * The clean case is not filler. An analyser that labelled everything would pass
 * every positive test in this file, and a report where every row carries a
 * finding is a report nobody reads twice. It is the honest negative, and it is
 * the reason the positives mean anything.
 */

function diagnosis(mixed ...$overrides): PageDiagnosis
{
    $defaults = [
        'wallMs' => 40.0,
        'dbMs' => 5.0,
        'livewireMs' => 6.0,   // 15% of wall — the normal range for a Blade page here
        'queries' => 5,
        'avoidableExecutions' => 0,
        'nPlusOneShapes' => 0,
        'responseBytes' => 50_000,
        'snapshotBytes' => 500,
        'hasChildHeavyComponent' => false,
        'outboundCalls' => 0,
        'uncacheable' => false,
        'queryBudget' => 10,
        'budgetsApply' => true,
    ];

    return new PageDiagnosis(...[...$defaults, ...$overrides]);
}

it('says ok, and nothing else, for a healthy page', function (): void {
    expect(diagnosis()->labels())->toBe(['ok'])
        ->and(diagnosis()->isOk())->toBeTrue()
        ->and(diagnosis()->status())->toBe(PageDiagnosis::STATUS_OK);
});

it('reports a repeated query', function (): void {
    expect(diagnosis(avoidableExecutions: 3)->labels())->toContain('repeated-query');
});

it('reports an N plus one', function (): void {
    expect(diagnosis(nPlusOneShapes: 1)->labels())->toContain('n-plus-one');
});

it('reports a page over its query budget', function (): void {
    $over = diagnosis(queries: 11, queryBudget: 10);

    expect($over->labels())->toContain('query-heavy')
        ->and($over->status())->toBe(PageDiagnosis::STATUS_OVER);
});

it('warns before a page goes over, not after', function (): void {
    expect(diagnosis(queries: 8, queryBudget: 10)->status())->toBe(PageDiagnosis::STATUS_WATCH)
        ->and(diagnosis(queries: 7, queryBudget: 10)->status())->toBe(PageDiagnosis::STATUS_OK);
});

it('treats a repeated query as OVER even when the count is inside the budget', function (): void {
    // A repeat is a defect regardless of the total. The budget is a ceiling on
    // how much work a page does; a repeat is work it did twice for one answer.
    expect(diagnosis(queries: 5, avoidableExecutions: 1)->status())->toBe(PageDiagnosis::STATUS_OVER);
});

it('labels a page nobody has budgeted, rather than omitting it', function (): void {
    $unbudgeted = diagnosis(queryBudget: null);

    expect($unbudgeted->labels())->toContain('unbudgeted')
        ->and($unbudgeted->status())->toBe(PageDiagnosis::STATUS_UNBUDGETED)
        // and it is never silently over, because there is nothing to be over
        ->and($unbudgeted->labels())->not->toContain('query-heavy');
});

it('calls a page database-bound only past half the request', function (): void {
    expect(diagnosis(wallMs: 40.0, dbMs: 20.0)->labels())->toContain('db-bound')
        ->and(diagnosis(wallMs: 40.0, dbMs: 19.0)->labels())->not->toContain('db-bound');
});

it('names Livewire only when Livewire is actually a large share', function (): void {
    /*
     * This replaced a rule that asked whether most of the time was OUTSIDE
     * Livewire. On a Blade-first application it always is — measured across
     * nine pages here, the non-Livewire share ran 66-87% and the label fired on
     * every one of them. The share that varies is Livewire's own.
     */
    expect(diagnosis(wallMs: 40.0, livewireMs: 16.0)->labels())->toContain('livewire-bound')
        ->and(diagnosis(wallMs: 40.0, livewireMs: 15.0)->labels())->not->toContain('livewire-bound');
});

it('does NOT call a Blade page livewire-bound just because Livewire is cheap', function (): void {
    // The regression the rename exists to prevent: 13-34% is the normal range
    // for a document page on this board, and none of it is a finding.
    expect(diagnosis(wallMs: 16.5, livewireMs: 3.2)->labels())->toBe(['ok']);
});

it('withholds a budget verdict when budgets do not apply to this measurement', function (): void {
    /*
     * The sweeper reads the real database; budgets are calibrated on the seeded
     * fixture. Judging one by the other marks almost every page query-heavy and
     * the label stops meaning anything.
     */
    $swept = diagnosis(queries: 99, queryBudget: 5, budgetsApply: false);

    expect($swept->labels())->not->toContain('query-heavy')
        ->and(diagnosis(queryBudget: null, budgetsApply: false)->labels())->not->toContain('unbudgeted');
});

it('names a child-heavy component', function (): void {
    expect(diagnosis(hasChildHeavyComponent: true)->labels())->toContain('child-heavy');
});

it('reports an oversized response', function (): void {
    expect(diagnosis(responseBytes: 300_000)->labels())->toContain('payload-heavy');
});

it('reports a heavy snapshot in absolute bytes', function (): void {
    // 5,948 bytes is the real ConsoleSearch measurement on this board.
    expect(diagnosis(snapshotBytes: 5_948)->labels())->toContain('snapshot-heavy');
});

it('reports a heavy snapshot relative to a small page', function (): void {
    // Under the absolute floor, but a twentieth of the page it rides on.
    expect(diagnosis(snapshotBytes: 2_000, responseBytes: 40_000)->labels())->toContain('snapshot-heavy');
});

it('reports an outbound call made inside a render', function (): void {
    expect(diagnosis(outboundCalls: 1)->labels())->toContain('vendor-bound');
});

it('survives a page that took no measurable time', function (): void {
    // Division by the wall clock is everywhere in here. A zero must not throw,
    // and must not report every ratio label at once.
    $instant = diagnosis(wallMs: 0.0, dbMs: 0.0, livewireMs: 0.0);

    expect($instant->labels())->not->toContain('db-bound')
        ->and($instant->labels())->not->toContain('render-bound');
});

it('reports a page no cache may hold', function (): void {
    /*
     * Livewire's SupportDisablingBackButtonCache sets `no-store` on EVERY page
     * that mounts a component. Correct behind a login; quietly expensive on a
     * public page, which is then uncacheable by any CDN because it happens to
     * carry one component. Verified on a real public home page.
     */
    expect(diagnosis(uncacheable: true)->labels())->toContain('uncacheable');
});

it('reports outbound HTTP made inside a render', function (): void {
    // The costliest thing a render can do, and the label was DEAD until now:
    // outboundCalls was hardcoded to 0, so it could never fire.
    expect(diagnosis(outboundCalls: 2)->labels())->toContain('vendor-bound');
});

it('does NOT call a trivially fast page database-bound', function (): void {
    /*
     * A real sweep flagged a 1.8 ms page because 0.9 ms of it was a settings
     * lookup in middleware. True, and nothing anybody would act on. Ratio labels
     * need an absolute floor or they describe arithmetic rather than a problem.
     */
    expect(diagnosis(wallMs: 1.8, dbMs: 0.9)->labels())->not->toContain('db-bound')
        ->and(diagnosis(wallMs: 16.0, dbMs: 9.0)->labels())->not->toContain('db-bound')
        ->and(diagnosis(wallMs: 60.0, dbMs: 35.0)->labels())->toContain('db-bound');
});

it('does NOT call a trivially fast page livewire-bound either', function (): void {
    expect(diagnosis(wallMs: 18.0, livewireMs: 9.0)->labels())->not->toContain('livewire-bound')
        ->and(diagnosis(wallMs: 40.0, livewireMs: 20.0)->labels())->toContain('livewire-bound');
});
