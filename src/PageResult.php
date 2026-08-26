<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * One page, measured several times, reduced to what can honestly be reported.
 *
 * THE MEDIAN, NEVER THE MEAN. One garbage-collection pause ruins a mean and
 * nobody reading the number can see that it did. The median of five survives it.
 *
 * THE COLD RUN IS KEPT AND SHOWN SEPARATELY, NOT AVERAGED IN. The first request
 * in a PHP process costs about 90 ms whatever page it is — measured on this
 * application, where `/` steady-states at 42 ms and read 150 ms when it ran
 * first. Folding that into an average produces a ranking that depends on the
 * order pages were visited, which is the failure this whole class exists to
 * prevent. Reporting it as its own column turns the trap into data.
 *
 * SPREAD IS THE TRUST COLUMN. A row where the fastest and slowest runs differ
 * wildly is a row whose median means little, and the reader must be able to see
 * that rather than infer it.
 */
final readonly class PageResult
{
    /**
     * @param  list<RequestMeasurement>  $runs  the timed runs, cold already excluded
     */
    public function __construct(
        public MeasurableRoute $route,
        public float $coldMs,
        public array $runs,
        public string $failure = '',
    ) {}

    public static function failed(MeasurableRoute $route, string $failure): self
    {
        return new self($route, 0.0, [], $failure);
    }

    public function measured(): bool
    {
        return $this->runs !== [] && $this->failure === '';
    }

    /**
     * The run whose wall time IS the median.
     *
     * Its own counts are reported rather than an average of counts: an averaged
     * query count can be 8.5, which is not a number of queries anybody can act
     * on, and rounding it invents a request that never happened.
     */
    public function representative(): ?RequestMeasurement
    {
        if ($this->runs === []) {
            return null;
        }

        $sorted = $this->runs;
        usort($sorted, static fn (RequestMeasurement $a, RequestMeasurement $b): int => $a->wallMs <=> $b->wallMs);

        return $sorted[intdiv(count($sorted), 2)];
    }

    public function medianWallMs(): float
    {
        $representative = $this->representative();

        return $representative instanceof RequestMeasurement ? $representative->wallMs : 0.0;
    }

    /**
     * How far the runs disagreed, as a share of the median.
     *
     * Above about 25% the ranking is noise and the report says so rather than
     * presenting an order it cannot justify.
     */
    /**
     * Whether the disagreement between runs is worth saying out loud.
     *
     * A PERCENTAGE ALONE IS NOT A FINDING, and a real sweep proved it: 24 of 32
     * pages were flagged, with spreads of 340%, 181% and 161% — all of them on
     * pages answering in single-digit milliseconds, where the whole spread was a
     * few milliseconds of ordinary machine jitter. A label that fires on
     * three-quarters of the rows cannot be told from a broken one.
     *
     * So it needs BOTH: a wide relative spread and an absolute gap big enough to
     * change where a page sits in the ranking.
     */
    public function isNoisy(): bool
    {
        if ($this->runs === []) {
            return false;
        }

        $times = array_map(static fn (RequestMeasurement $m): float => $m->wallMs, $this->runs);

        return $this->spreadPercent() >= 40.0 && (max($times) - min($times)) >= 15.0;
    }

    public function spreadPercent(): float
    {
        if ($this->runs === []) {
            return 0.0;
        }

        $times = array_map(static fn (RequestMeasurement $m): float => $m->wallMs, $this->runs);
        $median = $this->medianWallMs();

        return $median > 0.0 ? (max($times) - min($times)) / $median * 100 : 0.0;
    }

    /**
     * What this page's numbers say, WITHOUT a budget verdict.
     *
     * The sweeper reads the real database and the budgets are calibrated on the
     * seeded fixture, so a budget comparison here would be a category error —
     * see the note on `PageDiagnosis::$budgetsApply`. Whether a page is inside
     * its budget is the host's budget test's question, asked against the fixture the
     * number came from.
     */
    public function diagnosis(): PageDiagnosis
    {
        $representative = $this->representative();

        return $representative instanceof RequestMeasurement
            ? $representative->diagnosis(null, budgetsApply: false)
            // Named, not positional: this list grows, and a bool landing in an
            // int slot is the kind of drift a compiler cannot always catch.
            : new PageDiagnosis(
                wallMs: 0,
                dbMs: 0,
                livewireMs: 0,
                queries: 0,
                avoidableExecutions: 0,
                nPlusOneShapes: 0,
                responseBytes: 0,
                snapshotBytes: 0,
                hasChildHeavyComponent: false,
                outboundCalls: 0,
                uncacheable: false,
                queryBudget: null,
                budgetsApply: false,
            );
    }
}
