<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Olgun\PagePerformance\LoadState;
use Olgun\PagePerformance\OpcacheState;

/*
 * Measured 2026-11-07 in a host: every run of one evening started at a load of 22 to 49 on a 12-core Mac, and the
 * same page spread 47% to 175%. The report must say before the table that the machine was busy, and say nothing on a
 * quiet machine.
 */

beforeEach(function (): void {
    Route::get('/plain', fn (): string => 'ok')->name('t.plain');
    config()->set('page-performance.include', ['t.']);
    // Only the load warning is under test here.
    app()->instance(OpcacheState::class, new OpcacheState(true));
});

it('warns before the table when the load is over the core count', function (): void {
    app()->instance(LoadState::class, new LoadState(37.5, 12));

    $this->artisan('perf:pages', ['--only' => 't.plain', '--repeat' => 1])
        ->expectsOutputToContain('the machine is BUSY: a 1-minute load of 37.5 on 12 cores')
        ->assertSuccessful();
});

it('says nothing about the load on a quiet machine, also at exactly the core count', function (?float $load, ?int $cores): void {
    app()->instance(LoadState::class, new LoadState($load, $cores));

    $this->artisan('perf:pages', ['--only' => 't.plain', '--repeat' => 1])
        ->doesntExpectOutputToContain('the machine is BUSY')
        ->assertSuccessful();
})->with([
    'a quiet machine' => [3.2, 12],
    'a load exactly at the core count' => [12.0, 12],
]);

it('carries the load warning in the JSON report, and null on a quiet machine', function (float $load, bool $busy): void {
    app()->instance(LoadState::class, new LoadState($load, 12));

    Artisan::call('perf:pages', ['--only' => 't.plain', '--repeat' => 1, '--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report)->toHaveKey('load_warning');

    $busy
        ? expect($report['load_warning'])->toStartWith('the machine is BUSY')->toContain('uptime')
        : expect($report['load_warning'])->toBeNull();
})->with(['busy' => [40.0, true], 'quiet' => [2.0, false]]);
