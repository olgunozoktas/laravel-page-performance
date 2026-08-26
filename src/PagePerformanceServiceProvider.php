<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use Illuminate\Support\ServiceProvider;
use Olgun\PagePerformance\Console\MeasurePages;

/**
 * Registers the command and nothing else.
 *
 * NOTHING IS BOUND, NO LISTENER IS REGISTERED, NO MIDDLEWARE IS ADDED. That is
 * the whole answer to "what does this cost in production": there is no
 * production code path into it. The collector is constructed by the command,
 * for the duration of the command, and unsubscribes in a `finally`.
 *
 * A provider that registered a `DB::listen` or a Livewire hook would cost a
 * branch on every request forever and would need an environment gate that can
 * be set wrong. This has neither.
 */
final class PagePerformanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/page-performance.php', 'page-performance');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([MeasurePages::class]);

        $this->publishes([
            __DIR__.'/../config/page-performance.php' => config_path('page-performance.php'),
        ], 'page-performance-config');
    }
}
