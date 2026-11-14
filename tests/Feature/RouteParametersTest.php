<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Olgun\PagePerformance\MeasurableRoute;
use Olgun\PagePerformance\RouteCatalogue;
use Olgun\PagePerformance\RouteParameters;

/*
 * A route with a parameter is measured only with a value that the host names.
 * The package never guesses one: a guessed value answers 404, and a 404 would
 * rank as the fastest page.
 */

beforeEach(function (): void {
    Route::get('/posts/{post}', fn (string $post): string => $post === 'known' ? 'the post' : abort(404))->name('p.show');
    Route::get('/users/{user}/posts/{post}', fn (string $user, string $post): string => $user.$post)->name('p.nested');
    Route::get('/archive/{year?}', fn (?string $year = null): string => (string) $year)->name('p.archive');
    Route::get('/plain', fn (): string => 'ok')->name('p.plain');

    config()->set('page-performance.include', ['p.']);
});

function catalogueRoute(RouteCatalogue $catalogue, string $name): MeasurableRoute
{
    foreach ($catalogue->all() as $route) {
        if ($route->name === $name) {
            return $route;
        }
    }

    throw new RuntimeException("No route {$name} in the catalogue.");
}

it('skips a route with a parameter that has no value, and names the parameter', function (): void {
    $route = catalogueRoute(RouteCatalogue::fromConfig(), 'p.show');

    expect($route->measurable)->toBeFalse()
        ->and($route->skipReason)->toContain('(post)')
        ->and($route->skipReason)->toContain('--param');
});

it('measures a route whose values the config names', function (): void {
    config()->set('page-performance.parameters', ['p.show' => ['post' => 'known']]);

    $route = catalogueRoute(RouteCatalogue::fromConfig(), 'p.show');

    expect($route->measurable)->toBeTrue()
        ->and($route->path())->toBe('/posts/known');
});

it('lets --param win over the config for the same parameter', function (): void {
    config()->set('page-performance.parameters', ['p.show' => ['post' => 'from-config']]);

    $route = catalogueRoute(RouteCatalogue::fromConfig(['p.show:post=from-option']), 'p.show');

    expect($route->path())->toBe('/posts/from-option');
});

it('keeps a route skipped while one of its parameters has no value', function (): void {
    $route = catalogueRoute(RouteCatalogue::fromConfig(['p.nested:user=7']), 'p.nested');

    expect($route->measurable)->toBeFalse()
        ->and($route->skipReason)->toContain('(post)')
        ->and($route->skipReason)->not->toContain('user');
});

it('fills every parameter of a nested route', function (): void {
    $route = catalogueRoute(RouteCatalogue::fromConfig(['p.nested:user=7', 'p.nested:post=known']), 'p.nested');

    expect($route->measurable)->toBeTrue()
        ->and($route->path())->toBe('/users/7/posts/known');
});

it('leaves out an optional parameter without a value', function (): void {
    $route = catalogueRoute(RouteCatalogue::fromConfig(), 'p.archive');

    expect($route->measurable)->toBeTrue()
        ->and($route->path())->toBe('/archive');
});

it('encodes a value so that it stays one path segment', function (): void {
    $filled = (new RouteParameters(['p.show' => ['post' => 'a/b c']]))->fill('p.show', 'posts/{post}');

    expect($filled['uri'])->toBe('posts/a%2Fb%20c')
        ->and($filled['missing'])->toBe([]);
});

it('refuses a --param that is not route.name:parameter=value', function (string $option): void {
    $this->artisan('perf:pages', ['--param' => [$option], '--repeat' => 1])
        ->expectsOutputToContain('route.name:parameter=value')
        ->assertExitCode(2);
})->with(['no-colon', 'p.show:=value', 'p.show:post', 'p.show:post=']);

/**
 * The table goes through a buffered stream, so these tests read the real
 * output instead of the mocked console.
 *
 * @param  list<string>  $params
 */
function perfPagesOutput(array $params): string
{
    test()->withoutMockingConsoleOutput();

    Artisan::call('perf:pages', ['--only' => 'p.show', '--param' => $params, '--repeat' => 1]);

    return Artisan::output();
}

it('measures the page that the --param value names', function (): void {
    $output = perfPagesOutput(['p.show:post=known']);

    expect($output)->toMatch('/\| p\.show \/posts\/known \| 200 \|/')
        ->and($output)->toContain('1 returned the page');
});

it('reports a value whose row is gone as NOT THE PAGE, never as a fast page', function (): void {
    $output = perfPagesOutput(['p.show:post=gone']);

    expect($output)->toMatch('/\| p\.show \/posts\/gone \| 404 \|.*NOT THE PAGE — answered 404/')
        ->and($output)->toContain('0 returned the page');
});
