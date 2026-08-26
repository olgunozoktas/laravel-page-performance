<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * One request, measured. The unit `PageMeasurer` takes the median of.
 */
final readonly class RequestMeasurement
{
    /**
     * @param  list<ComponentTiming>  $components
     */
    public function __construct(
        public int $status,
        public float $wallMs,
        public float $livewireMs,
        public QueryDigest $queries,
        public SnapshotReader $snapshots,
        public array $components,
        public int $bytes,
        public OutboundCalls $outbound = new OutboundCalls,
        public string $cacheControl = '',
    ) {}

    public function dbMs(): float
    {
        return $this->queries->totalMs();
    }

    /**
     * Time this request spent OUTSIDE Livewire: middleware, routing, the
     * controller, the shared page data, the response.
     *
     * On this application it is usually the larger half, and being able to say
     * so with a number is the difference between "optimise Livewire" and
     * "optimise the thing that is actually slow".
     */
    public function nonLivewireMs(): float
    {
        return max(0.0, $this->wallMs - $this->livewireMs);
    }

    public function hasChildHeavyComponent(): bool
    {
        return array_any($this->components, fn (ComponentTiming $component): bool => $component->isChildHeavy());
    }

    /**
     * Whether this response told every cache to keep nothing.
     *
     * Livewire's `SupportDisablingBackButtonCache` runs on EVERY component boot
     * and sets `no-store`. That is right for a page behind a login and quietly
     * expensive for one that is not: a marketing or board page you wanted a CDN
     * to hold is uncacheable because it mounts one component. Verified on a real
     * public home page, which carried `no-store, private` for exactly that
     * reason.
     *
     * Only worth saying when a component is actually on the page — a `no-store`
     * an application set on purpose is a decision, not a finding.
     */
    public function isUncacheable(): bool
    {
        return $this->snapshots->count() > 0 && str_contains($this->cacheControl, 'no-store');
    }

    public function diagnosis(?int $queryBudget, bool $budgetsApply = true): PageDiagnosis
    {
        return new PageDiagnosis(
            wallMs: $this->wallMs,
            dbMs: $this->dbMs(),
            livewireMs: $this->livewireMs,
            queries: $this->queries->count(),
            avoidableExecutions: $this->queries->avoidableExecutions(),
            nPlusOneShapes: count($this->queries->nPlusOne()),
            responseBytes: $this->bytes,
            snapshotBytes: $this->snapshots->totalBytes(),
            hasChildHeavyComponent: $this->hasChildHeavyComponent(),
            outboundCalls: $this->outbound->count(),
            uncacheable: $this->isUncacheable(),
            queryBudget: $queryBudget,
            budgetsApply: $budgetsApply,
        );
    }
}
