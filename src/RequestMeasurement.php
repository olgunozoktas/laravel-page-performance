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
        public string $html = '',
    ) {}

    /**
     * What the visitor actually downloads.
     *
     * THE UNCOMPRESSED FIGURE IS NOT THE COST, and reporting it alone sends
     * people on refactors worth nothing. Measured on a real board home page:
     * 287,831 bytes of HTML arrives as 32,514 over the wire — 89% smaller —
     * because the things that make server-rendered HTML big are repeated
     * Tailwind classes, repeated inline SVG and framework comments, and
     * repetition is precisely what a compressor removes.
     *
     * This nearly cost a day: 12.5 KB of duplicated SVG and 21.9 KB of
     * identical Livewire morph markers both looked like obvious wins and both
     * compress to almost nothing. An icon-sprite refactor would have risked a
     * visual regression on every page of the application to save roughly two
     * hundred bytes.
     *
     * gzip at the default level, because that is what a web server does. It is
     * an estimate of the wire size, not a measurement of one — the in-process
     * request never reaches nginx.
     */
    public function compressedBytes(): int
    {
        $encoded = gzencode($this->html, 6);

        return $encoded === false ? $this->bytes : strlen($encoded);
    }

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
            responseBytes: $this->compressedBytes(),
            snapshotBytes: $this->snapshots->totalBytes(),
            hasChildHeavyComponent: $this->hasChildHeavyComponent(),
            outboundCalls: $this->outbound->count(),
            uncacheable: $this->isUncacheable(),
            queryBudget: $queryBudget,
            budgetsApply: $budgetsApply,
        );
    }
}
