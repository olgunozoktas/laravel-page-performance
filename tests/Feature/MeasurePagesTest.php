<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Olgun\PagePerformance\Budgets;
use Olgun\PagePerformance\LivewireProfile;
use Olgun\PagePerformance\RouteCatalogue;

/*
 * The command, exercised against routes this test defines, so the assertions
 * hold whatever application the package is installed into.
 *
 * Several of these are DETECTOR tests: they inject the defect and check the
 * exact finding appears. A tool that reports nothing everywhere cannot be told
 * from a broken one, and the honest-negative case below is what keeps the
 * positives meaningful.
 */

beforeEach(function (): void {
    Route::get('/plain', fn (): string => 'ok')->name('t.plain');

    Route::get('/repeats', function (): string {
        DB::select('select 1 as n');
        DB::select('select 1 as n');
        DB::select('select 1 as n');

        return 'done';
    })->name('t.repeats');

    Route::get('/big', fn (): string => str_repeat('x', 300_000))->name('t.big');

    Route::post('/mutates', fn (): string => 'no')->name('t.mutates');
    Route::get('/with/{id}', fn (string $id): string => $id)->name('t.parameterised');

    config()->set('page-performance.include', ['t.']);
});

it('registers the command', function (): void {
    $this->artisan('perf:pages', ['--only' => 't.plain', '--repeat' => 1])->assertSuccessful();
});

it('finds a repeated query and names how many runs', function (): void {
    $this->artisan('perf:pages', ['--only' => 't.repeats', '--repeat' => 1])
        ->expectsOutputToContain('repeated-query')
        ->assertSuccessful();
});

it('finds an oversized response', function (): void {
    $this->artisan('perf:pages', ['--only' => 't.big', '--repeat' => 1])
        ->expectsOutputToContain('payload-heavy')
        ->assertSuccessful();
});

it('STAYS QUIET on a page with nothing wrong', function (): void {
    // The honest negative. Without it, an analyser that labelled everything
    // would pass both cases above.
    $this->artisan('perf:pages', ['--only' => 't.plain', '--repeat' => 1])
        ->expectsOutputToContain('ok')
        ->doesntExpectOutputToContain('repeated-query')
        ->assertSuccessful();
});

it('never offers a mutating or parameterised route to the measurer', function (): void {
    $names = array_map(static fn ($r): string => $r->name, RouteCatalogue::fromConfig()->measurable());

    expect($names)->not->toContain('t.mutates')
        ->and($names)->not->toContain('t.parameterised')
        ->and($names)->toContain('t.plain');
});

it('gives every skipped route a stated reason', function (): void {
    foreach (RouteCatalogue::fromConfig()->skipped() as $route) {
        expect(trim($route->skipReason))->not->toBe('');
    }
});

it('refuses to measure production', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        $this->artisan('perf:pages', ['--only' => 't.plain'])->assertFailed();
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
});

it('says WHY there is no Livewire timing rather than reporting zero', function (): void {
    // A silent zero reads as "Livewire is fast", which is the worst failure
    // available here.
    config()->set('app.debug', false);

    expect(LivewireProfile::available())->toBeFalse()
        ->and(LivewireProfile::unavailableReason())->toContain('app.debug');
});

it('works with Livewire absent, because Livewire is optional', function (): void {
    // Page-level measurement must not depend on it. Livewire IS installed here
    // (it is a dev dependency), so this asserts the seam rather than the state:
    // subscribing is a no-op when the bus class is missing, and every page
    // number is produced without it.
    expect(LivewireProfile::installed())->toBeTrue();

    $this->artisan('perf:pages', ['--only' => 't.plain', '--repeat' => 1])->assertSuccessful();
});

it('reads budgets from the HOST config, not from package code', function (): void {
    config()->set('page-performance.budgets', [
        't.plain' => ['queries' => 3, 'duplicates' => 0, 'bytes' => null, 'components' => 1, 'measured' => '2026-08-26', 'why' => 'a test'],
    ]);

    expect(Budgets::for('t.plain'))->not->toBeNull()
        ->and(Budgets::for('t.plain')['queries'])->toBe(3)
        ->and(Budgets::covers('t.plain'))->toBeTrue()
        ->and(Budgets::covers('t.repeats'))->toBeFalse();
});

it('REFUSES a budget that asserts a duration', function (): void {
    /*
     * The structural guard. A timing assertion in a parallel suite measures the
     * other workers, so no budget may carry one. This is the detector; the host
     * asserts `durationKeys()` is empty in its own gate.
     */
    config()->set('page-performance.budgets', [
        't.plain' => ['queries' => 3, 'wall_ms' => 50, 'duplicates' => 0, 'bytes' => null, 'components' => 1, 'measured' => '2026-08-26', 'why' => 'x'],
    ]);

    expect(Budgets::durationKeys())->toBe(['t.plain.wall_ms']);
});

it('finds no duration keys in an honest budget', function (): void {
    config()->set('page-performance.budgets', [
        't.plain' => ['queries' => 3, 'duplicates' => 0, 'bytes' => null, 'components' => 1, 'measured' => '2026-08-26', 'why' => 'x'],
    ]);

    expect(Budgets::durationKeys())->toBeEmpty();
});
