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
        /** The path actually measured, when a redirect was followed. */
        public ?string $followedTo = null,
        /**
         * Child id => ms the parent spent on it.
         *
         * @var array<string, float>
         */
        public array $childMs = [],
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

    /**
     * Did this response actually contain the page?
     *
     * `Kernel::handle()` turns an exception into a 500 RESPONSE, so a broken
     * page never reaches the harness's catch block — it arrives as an ordinary
     * row. Measured on a real board: five routes answered 404 because their
     * feature flags were off, and every one was reported as a healthy page with
     * two queries and no defects. They sorted to the BOTTOM of an evidence-first
     * ranking, which reads as the healthiest pages in the application.
     *
     * A 404 is not a fast page. It is not the page at all.
     */
    public function answeredWithThePage(): bool
    {
        return $this->status >= 200 && $this->status < 300;
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
        return array_any($this->components, fn (ComponentTiming $c): bool => $c->isChildHeavy($this->childMs));
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

    /**
     * Whether this page could be shared-cached AT ALL, Livewire aside.
     *
     * THIS EXISTS BECAUSE THE FINDING WAS DANGEROUS WITHOUT IT. Reporting
     * "Livewire set no-store" invites somebody to remove the header and put the
     * page behind a CDN — and on a real board home page that would have served
     * one visitor's CSRF token, session cookie and Livewire snapshot checksum
     * to the next visitor. A finding that names a cause and not the blocker is
     * a finding that gets fixed the wrong way.
     *
     * A page carrying a per-session token cannot be shared-cached whatever
     * Livewire does. Saying so turns "this is uncacheable" from a defect into a
     * fact about the page.
     */
    public function carriesPerSessionState(): bool
    {
        return str_contains($this->html, 'csrf-token')
            || str_contains($this->html, 'name="_token"')
            || str_contains($this->html, 'wire:snapshot');
    }

    /** The component name behind an id, for naming a heavy child. */
    public function componentName(string $id): string
    {
        foreach ($this->components as $component) {
            if ($component->id === $id) {
                return $component->name;
            }
        }

        return $id;
    }

    public function diagnosis(?int $queryBudget, bool $budgetsApply = true, ?float $runMedianMs = null): PageDiagnosis
    {
        return new PageDiagnosis(
            wallMs: $this->wallMs,
            dbMs: $this->dbMs(),
            livewireMs: $this->livewireMs,
            queries: $this->queries->count(),
            avoidableExecutions: $this->queries->avoidableExecutions(),
            nPlusOneShapes: count($this->queries->nPlusOne()),
            // Compressed, because `payload-heavy` judges what a visitor pays.
            responseBytes: $this->compressedBytes(),
            // UNCOMPRESSED, because `snapshot-heavy` compares it against a
            // snapshot length that is also uncompressed. Dividing a decoded
            // JSON length by a gzip estimate made the 3% rule behave like 0.33%
            // at this application's 9:1 ratio, which would have fired the label
            // on nearly every Livewire page.
            uncompressedBytes: $this->bytes,
            snapshotBytes: $this->snapshots->totalBytes(),
            hasChildHeavyComponent: $this->hasChildHeavyComponent(),
            outboundCalls: $this->outbound->count(),
            uncacheable: $this->isUncacheable(),
            queryBudget: $queryBudget,
            budgetsApply: $budgetsApply,
            runMedianMs: $runMedianMs,
        );
    }
}
