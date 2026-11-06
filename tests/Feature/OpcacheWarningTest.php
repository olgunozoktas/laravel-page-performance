<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Olgun\PagePerformance\OpcacheState;

/*
 * Measured 2026-11-07 in a host: one component took 15.9 ms with CLI opcache off and 6.7 ms with it on, and only the run
 * without opcache reported child-heavy. The report must say before the table that its times include view compilation,
 * and say nothing when opcache is on.
 */

beforeEach(function (): void {
    Route::get('/plain', fn (): string => 'ok')->name('t.plain');
    config()->set('page-performance.include', ['t.']);
});

it('warns before the table when opcache is off in the measuring CLI', function (): void {
    app()->instance(OpcacheState::class, new OpcacheState(false));

    $this->artisan('perf:pages', ['--only' => 't.plain', '--repeat' => 1])
        // One line holds the warning and the command, and Laravel uses up a line per match: one expectation here,
        // and the JSON test below checks the command.
        ->expectsOutputToContain('opcache is OFF in this CLI: each render compiles the views again')
        ->assertSuccessful();
});

it('says nothing about opcache when it is on', function (): void {
    app()->instance(OpcacheState::class, new OpcacheState(true));

    $this->artisan('perf:pages', ['--only' => 't.plain', '--repeat' => 1])
        ->doesntExpectOutputToContain('opcache is OFF')
        ->assertSuccessful();
});

it('carries the same warning in the JSON report, and null when opcache is on', function (bool $enabled): void {
    app()->instance(OpcacheState::class, new OpcacheState($enabled));

    Artisan::call('perf:pages', ['--only' => 't.plain', '--repeat' => 1, '--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report)->toHaveKey('opcache_warning')
        ->and($report['conditions']['Runtime'])->toContain($enabled ? 'opcache on' : 'opcache off');

    $enabled
        ? expect($report['opcache_warning'])->toBeNull()
        : expect($report['opcache_warning'])->toStartWith('opcache is OFF in this CLI')->toContain('php -d opcache.enable_cli=1 artisan perf:pages');
})->with(['opcache off' => false, 'opcache on' => true]);
