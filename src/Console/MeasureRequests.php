<?php

declare(strict_types=1);

namespace Olgun\PagePerformance\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Olgun\PagePerformance\Budgets;
use Olgun\PagePerformance\LivewireProfile;
use Olgun\PagePerformance\LoadState;
use Olgun\PagePerformance\MeasurableRequest;
use Olgun\PagePerformance\PageMeasurer;
use Olgun\PagePerformance\PageReport;
use Olgun\PagePerformance\PageResult;
use Olgun\PagePerformance\RequestSandbox;
use Olgun\PagePerformance\Watchdog;
use Throwable;

/**
 * What does each named request cost: a POST, an API write, an MCP call?
 *
 * `perf:pages` sends GET only. An API is mostly writes with a key and a JSON
 * body, and none of them could be measured. This command sends the requests
 * that the host names in `page-performance.requests`, with the same measurer,
 * the same report and the same Defects.
 *
 * IT WRITES, AND NOTHING STAYS. Each run is in a transaction that is rolled
 * back, an outbound call is refused, and mail and jobs go nowhere
 * ({@see RequestSandbox}). It still refuses to run in production.
 *
 * IT JUDGES BUDGETS. A request runs on the rows of the host's fixture, so a
 * budget keyed by its name means the same thing on every run.
 */
final class MeasureRequests extends Command
{
    use RendersPageReport;

    protected $signature = 'perf:requests
        {--repeat=5 : Timed iterations per request; the first is always discarded}
        {--only= : Substring filter on the request name}
        {--timeout=15 : Seconds one request may take before it is called a loop; 0 switches the watchdog off}
        {--open= : Open finding N in the configured editor (for terminals with no clickable links)}
        {--json : Machine-readable report}';

    protected $description = 'Measure each named request of page-performance.requests (any method, headers, a JSON body), rolled back';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->components->error('perf:requests sends writes. It will not do that against production.');

            return self::FAILURE;
        }

        $this->raiseMemoryLimit();

        try {
            $requests = $this->requests();
            $sandbox = RequestSandbox::fromConfig();
        } catch (InvalidArgumentException $invalid) {
            $this->components->error($invalid->getMessage());

            return self::INVALID;
        }

        if ($requests === []) {
            $this->components->error('No named request matched (page-performance.requests). Nothing was measured, which is NOT a pass.');

            return 2;
        }

        $load = app(LoadState::class)->measured();

        $measurer = new PageMeasurer(
            warmup: config()->integer('page-performance.warmup', 1),
            iterations: max(1, (int) $this->option('repeat')),
            watchdog: new Watchdog((int) $this->option('timeout')),
            sandbox: $sandbox,
        );

        try {
            $results = $sandbox->around(fn (array $values): array => array_map(
                fn (MeasurableRequest $request): PageResult => $this->measure($measurer, $request, $values),
                $requests,
            ));
        } catch (Throwable $failed) {
            // Only the fixture can throw here: a request's own failure is a row.
            $this->components->error(sprintf('The fixture failed, so nothing was measured: %s', $failed->getMessage()));

            return self::FAILURE;
        }

        $only = $this->option('only');

        $report = new PageReport(
            $results,
            [],
            LivewireProfile::available(),
            is_string($only) ? $only : '',
            watchdogSeconds: (int) $this->option('timeout'),
            loadState: $load,
            requests: true,
        );

        if ($this->option('json') === true) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $report->everythingMeasured() ? self::SUCCESS : 2;
        }

        $this->render($report);

        return $report->everythingMeasured() ? self::SUCCESS : 2;
    }

    /**
     * A request with a budget is judged by it. A request with an `unbudgeted`
     * reason is not judged. A request with neither is the `unbudgeted` Defect.
     *
     * @param  array<string, string|int|float|bool>  $values
     */
    private function measure(PageMeasurer $measurer, MeasurableRequest $request, array $values): PageResult
    {
        $budget = Budgets::for($request->name);

        return $measurer->measureRequest(
            $request,
            $values,
            queryBudget: $budget['queries'] ?? null,
            budgetsApply: $budget !== null || ! array_key_exists($request->name, Budgets::unbudgeted()),
        );
    }

    /**
     * @return list<MeasurableRequest>
     */
    private function requests(): array
    {
        $requests = MeasurableRequest::allFromConfig();
        $only = $this->option('only');

        if (is_string($only) && $only !== '') {
            $requests = array_values(array_filter($requests, static fn (MeasurableRequest $r): bool => str_contains($r->name, $only)));
        }

        return $requests;
    }
}
