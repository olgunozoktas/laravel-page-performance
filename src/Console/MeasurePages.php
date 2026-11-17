<?php

declare(strict_types=1);

namespace Olgun\PagePerformance\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Olgun\PagePerformance\LivewireProfile;
use Olgun\PagePerformance\LoadState;
use Olgun\PagePerformance\MeasurableRoute;
use Olgun\PagePerformance\PageMeasurer;
use Olgun\PagePerformance\PageReport;
use Olgun\PagePerformance\RouteCatalogue;
use Olgun\PagePerformance\Watchdog;

/**
 * What does each page cost, and why?
 *
 * READ-ONLY, AND IT REFUSES TO RUN IN PRODUCTION. It requests GET routes only
 * (with no parameters, or with the values the host names), so nothing it does
 * can change what it is measuring.
 *
 * IT MEASURES THE REAL LOCAL DATABASE, NOT THE TEST FIXTURE, and that is the
 * decision that makes it worth running. On an empty database the board home
 * renders no promotions — about 30 KB instead of 248 KB and 8 queries instead
 * of 20 — which is not a pessimistic version of the truth, it is a number about
 * software nobody ships. A budget test against a seeded fixture covers that case, which is the
 * right shape for a gate; this is the instrument.
 *
 * IT DOES NOT JUDGE BUDGETS. Budgets are calibrated on the seeded fixture and
 * this reads live data, so comparing them would mark almost every page over and
 * the label would stop meaning anything.
 */
final class MeasurePages extends Command
{
    use RendersPageReport;

    protected $signature = 'perf:pages
        {--as= : Sign in as this local user (id or email) so authenticated pages are measured}
        {--repeat=5 : Timed iterations per page; the first is always discarded}
        {--only= : Substring filter on the route name}
        {--param=* : A value for a route parameter, as route.name:parameter=value (repeatable; wins over page-performance.parameters)}
        {--shuffle : Randomise page order, so an ordering effect becomes visible}
        {--timeout=15 : Seconds one page may take before it is called a loop; 0 switches the watchdog off}
        {--open= : Open finding N in the configured editor (for terminals with no clickable links)}
        {--json : Machine-readable report}';

    protected $description = 'Measure every GET page whose parameters are known and report what it costs, worst first';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->components->error('perf:pages measures by making requests. It will not do that against production.');

            return self::FAILURE;
        }

        $this->raiseMemoryLimit();

        try {
            $catalogue = RouteCatalogue::fromConfig($this->parameterOptions());
        } catch (InvalidArgumentException $invalid) {
            $this->components->error($invalid->getMessage());

            return self::INVALID;
        }

        $routes = $this->routes($catalogue);

        if ($routes === []) {
            $this->components->error('No measurable routes matched. Nothing was measured, which is NOT a pass.');

            return 2;
        }

        // Before the first page: the load that the measurement itself makes must not count.
        $load = app(LoadState::class)->measured();

        $measurer = new PageMeasurer(
            warmup: config()->integer('page-performance.warmup', 1),
            iterations: max(1, (int) $this->option('repeat')),
            actingAs: $this->actor(),
            watchdog: new Watchdog((int) $this->option('timeout')),
        );

        $results = [];

        foreach ($routes as $route) {
            $results[] = $measurer->measure($route);
        }

        $only = $this->option('only');

        $report = new PageReport(
            $results,
            $catalogue->skipped(),
            LivewireProfile::available(),
            is_string($only) ? $only : '',
            watchdogSeconds: (int) $this->option('timeout'),
            loadState: $load,
        );

        if ($this->option('json') === true) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $report->everythingMeasured() ? self::SUCCESS : 2;
        }

        $this->render($report);

        return $report->everythingMeasured() ? self::SUCCESS : 2;
    }

    /**
     * @return list<MeasurableRoute>
     */
    private function routes(RouteCatalogue $catalogue): array
    {
        $routes = $catalogue->measurable();

        $only = $this->option('only');

        if (is_string($only) && $only !== '') {
            $routes = array_values(array_filter($routes, static fn (MeasurableRoute $r): bool => str_contains($r->name, $only)));
        }

        if ($this->option('shuffle') === true) {
            shuffle($routes);
        }

        return $routes;
    }

    /**
     * @return list<string>
     */
    private function parameterOptions(): array
    {
        $options = $this->option('param');

        return is_array($options) ? array_values(array_filter($options, is_string(...))) : [];
    }

    /**
     * The user to measure authenticated pages as, resolved through the HOST's
     * own auth provider.
     *
     * A package cannot name `App\Models\User` — plenty of applications do not
     * have one, and the ones that do may not call it that. The configured
     * provider already knows what a user is here, so it is asked instead.
     */
    private function actor(): ?Authenticatable
    {
        $as = $this->option('as');

        if (! is_string($as) || $as === '') {
            return null;
        }

        $provider = Auth::createUserProvider(config()->string('auth.guards.web.provider', 'users'));

        $user = $provider?->retrieveByCredentials(['email' => $as]);

        if (! $user instanceof Authenticatable && ctype_digit($as)) {
            $user = $provider?->retrieveById($as);
        }

        if (! $user instanceof Authenticatable) {
            $this->components->warn(sprintf('No user matched "%s" through the configured provider. Only public pages will be measured.', $as));
        }

        return $user;
    }
}
