<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
 * The sweep's memory floor (page-performance.memory_limit, 2026-11-06): a full sweep of a Livewire app ran out of the
 * CLI default of 128M. The command raises the limit to the floor, never lowers it, and leaves it alone when the floor
 * is empty. Each case restores the process's own limit.
 */

function withMemoryLimit(string $limit, Closure $body): string
{
    $before = (string) ini_get('memory_limit');
    ini_set('memory_limit', $limit);

    try {
        $body();

        return (string) ini_get('memory_limit');
    } finally {
        ini_set('memory_limit', $before);
    }
}

it('raises a lower limit to the floor', function (): void {
    config()->set('page-performance.memory_limit', '1G');

    expect(withMemoryLimit('128M', fn () => Artisan::call('perf:pages', ['--only' => 'no-such-route'])))->toBe('1G');
});

it('never lowers a higher limit, and keeps no limit', function (): void {
    config()->set('page-performance.memory_limit', '1G');

    expect(withMemoryLimit('2G', fn () => Artisan::call('perf:pages', ['--only' => 'no-such-route'])))->toBe('2G')
        ->and(withMemoryLimit('-1', fn () => Artisan::call('perf:pages', ['--only' => 'no-such-route'])))->toBe('-1');
});

it('leaves the limit as it is when the floor is empty', function (): void {
    config()->set('page-performance.memory_limit', '');

    expect(withMemoryLimit('128M', fn () => Artisan::call('perf:pages', ['--only' => 'no-such-route'])))->toBe('128M');
});
