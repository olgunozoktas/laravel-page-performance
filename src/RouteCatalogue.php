<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * The pages a latency sweep may request, read from the real route collection.
 *
 * THE FILTER IS NEARLY THE INVERSE OF THE SECURITY SWEEP'S, AND THAT IS THE
 * POINT. `EveryWorkspaceRouteRefusesOutsidersTest` can put `no-such-thing-999`
 * in every parameter because it asserts a REFUSAL — a 404 is the pass. A
 * latency sweep needs a 200: a 404 answers in about 3 ms and would rank as the
 * fastest page in the application, so a parameterised route is excluded and
 * COUNTED rather than guessed at.
 *
 * ONLY GET, AND ONLY WITHOUT PARAMETERS. A sweep that fired a DELETE would have
 * changed the thing it was measuring, so the mutating verbs are excluded
 * structurally rather than by a list somebody has to maintain.
 *
 * IT READS THE ROUTER, NEVER A HAND-WRITTEN LIST. A list is the thing that
 * silently stops covering the surface it is named after — a page added tomorrow
 * is in scope tomorrow, and the host's budget test fails until somebody budgets it or
 * writes down why not.
 */
final readonly class RouteCatalogue
{
    /**
     * @param  list<string>  $include  route-name prefixes; empty means every route
     * @param  array<string, string>  $exclude  route name => the reason it is skipped
     */
    public function __construct(
        private array $include = [],
        private array $exclude = [],
    ) {}

    public static function fromConfig(): self
    {
        /** @var list<string> $include */
        $include = config()->array('page-performance.include', []);

        /** @var array<string, string> $exclude */
        $exclude = config()->array('page-performance.exclude', []);

        return new self($include, $exclude);
    }

    /**
     * Every candidate, measurable or not, in name order.
     *
     * @return list<MeasurableRoute>
     */
    public function all(): array
    {
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null) {
                continue;
            }
            if (isset($found[$name])) {
                continue;
            }
            if (! $this->included($name)) {
                continue;
            }

            $found[$name] = $this->classify($route, $name);
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * @return list<MeasurableRoute>
     */
    public function measurable(): array
    {
        return array_values(array_filter($this->all(), static fn (MeasurableRoute $r): bool => $r->measurable));
    }

    /**
     * @return list<MeasurableRoute>
     */
    public function skipped(): array
    {
        return array_values(array_filter($this->all(), static fn (MeasurableRoute $r): bool => ! $r->measurable));
    }

    private function classify(RoutingRoute $route, string $name): MeasurableRoute
    {
        $uri = $route->uri();

        if (isset($this->exclude[$name])) {
            return MeasurableRoute::skipped($name, $uri, $this->exclude[$name]);
        }

        if (! in_array('GET', $route->methods(), true)) {
            return MeasurableRoute::skipped($name, $uri, 'Not a GET route. A sweep that fired it would change what it measured.');
        }

        if (str_contains($uri, '{')) {
            return MeasurableRoute::skipped($name, $uri, 'Takes a route parameter. A guessed value answers 404, which would rank as the fastest page here.');
        }

        return MeasurableRoute::measurable($name, $uri);
    }

    private function included(string $name): bool
    {
        if ($this->include === []) {
            return true;
        }

        return array_any($this->include, static fn (string $prefix): bool => str_starts_with($name, $prefix));
    }
}
