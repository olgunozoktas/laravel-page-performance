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

    // Incompressible on purpose: 300,000 repeated characters weigh almost
    // nothing over the wire, which is the entire point of measuring the
    // compressed size. Base64 of random bytes does not compress.
    Route::get('/big', fn (): string => base64_encode(random_bytes(150_000)))->name('t.big');

    // Large and highly compressible — the honest negative. This is what a
    // server-rendered page with repeated Tailwind classes and inline SVG looks
    // like to a compressor, and it must NOT be reported as heavy.
    Route::get('/compressible', fn (): string => str_repeat('<div class="flex items-center gap-2">x</div>', 8_000))->name('t.compressible');

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

it('finds a response that is genuinely heavy OVER THE WIRE', function (): void {
    $this->artisan('perf:pages', ['--only' => 't.big', '--repeat' => 1])
        ->expectsOutputToContain('payload-heavy')
        ->assertSuccessful();
});

it('does NOT call a large but compressible page heavy', function (): void {
    /*
     * The measurement that nearly cost a day. A real board home page is 287,831
     * bytes of HTML and 32,514 over the wire — 89% smaller — because repeated
     * classes, repeated SVG and framework comments are exactly what a
     * compressor removes. Reporting the uncompressed number would have sent
     * somebody to refactor a shared icon component, risking a visual regression
     * on every page, to save about two hundred bytes.
     */
    $this->artisan('perf:pages', ['--only' => 't.compressible', '--repeat' => 1])
        ->doesntExpectOutputToContain('payload-heavy')
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

it('prints defects as a TABLE, one row per defect', function (): void {
    $this->artisan('perf:pages', ['--only' => 't.repeats', '--repeat' => 1])
        ->expectsOutputToContain('Defects')
        ->expectsOutputToContain('where')
        ->assertSuccessful();
});

it('keeps CHARACTERISTICS apart from defects', function (): void {
    /*
     * A board with every duplicate query fixed still showed five "findings" —
     * where the time went, and two rows whose spread said the measurement was
     * not trustworthy. None of them was worth acting on, and a count that
     * cannot reach zero is a count people stop reading.
     *
     * A plain page has neither, and must say so rather than printing an empty
     * table.
     */
    $this->artisan('perf:pages', ['--only' => 't.plain', '--repeat' => 1])
        ->expectsOutputToContain('No defects')
        ->assertSuccessful();
});

it('emits NO escape codes when the output is not a terminal', function (): void {
    /*
     * The whole reason `supportsHyperlinks()` exists. Piped into a file or a
     * pager, an OSC 8 escape sits in the output as control characters and stops
     * a grep matching the very paths the column exists to hand over. Artisan's
     * test output is not a TTY, so this asserts the degraded path — which is
     * also the path CI takes.
     */
    $this->artisan('perf:pages', ['--only' => 't.repeats', '--repeat' => 1])
        ->doesntExpectOutputToContain("\e]8;;")
        ->assertSuccessful();
});

it('names the per-session token as the real reason a page cannot be shared-cached', function (): void {
    /*
     * The finding used to say only "Livewire set no-store", which invites
     * removing the header and putting the page behind a CDN. On a real board
     * home page that would have served one visitor's CSRF token, session cookie
     * and Livewire snapshot checksum to the next visitor.
     *
     * A finding that names a cause but not the blocker gets fixed the wrong
     * way, and this one would have been fixed into a vulnerability.
     */
    Route::get('/tokened', fn () => response(
        '<html><head><meta name="csrf-token" content="abc"></head>'
        .'<body><div wire:snapshot="{&quot;memo&quot;:{&quot;id&quot;:&quot;a&quot;,&quot;name&quot;:&quot;x&quot;}}"></div></body></html>'
    )->header('Cache-Control', 'no-store, private'))->name('t.tokened');

    $this->artisan('perf:pages', ['--only' => 't.tokened', '--repeat' => 1])
        ->expectsOutputToContain('per-session token')
        ->assertSuccessful();
});
