<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * One page's measurements, turned into labels that name a fix.
 *
 * WHY LABELS AND NOT A SCORE. The owner asked to "easily find optimization". A
 * column of milliseconds does not answer that — it says which page is slowest,
 * never what to open. Every label here maps to one remedy, and every rule is a
 * stated number rather than a judgement, so a label can be argued with instead
 * of trusted.
 *
 * WHY `ok` IS A LABEL AND NOT AN EMPTY CELL. A healthy row and a row the tool
 * failed to measure must not look the same. Silence is not a measurement.
 *
 * WHY `unbudgeted` EXISTS. A page nobody has budgeted is a page nobody defends,
 * and a report that simply omitted it would read as coverage. It is the
 * anti-silence label, and it is the one that fires the day somebody adds a page.
 *
 * MILLISECONDS ARE INPUTS HERE, NEVER OUTPUTS. `db-bound` and `render-bound` are
 * RATIOS of one measurement to another taken in the same process at the same
 * moment, so they survive a slow machine intact — an absolute millisecond
 * threshold would not, and this class is read by a gate.
 */
final readonly class PageDiagnosis
{
    public const string STATUS_OVER = 'OVER';

    public const string STATUS_WATCH = 'WATCH';

    public const string STATUS_OK = 'OK';

    public const string STATUS_UNBUDGETED = 'NOBUD';

    /**
     * More than half the request spent in the database is a database problem.
     *
     * Half, not 40%: measured across this board's pages the database share runs
     * 40-60%, so a 40% rule fired on every page — and a label that fires
     * everywhere cannot be told from a broken one.
     */
    private const float DB_BOUND_RATIO = 0.50;

    /**
     * Below this, a share is arithmetic rather than a finding.
     *
     * Measured across two real sweeps. At no floor, a 1.8 ms page reported
     * `db-bound` because 0.9 ms of it was a settings lookup in middleware. At a
     * 5 ms floor, thirteen pages still reported it — every one a document page
     * spending 5-8 ms across a handful of small queries, where the share is high
     * only because the page does so little else.
     *
     * Ten is the number where the label starts naming pages somebody would open:
     * it keeps the board home at 35 ms of database time and drops the documents.
     * Under 10 ms of database work there is nothing worth winning.
     */
    private const float MATERIAL_MS = 10.0;

    /**
     * Livewire taking this share of a request is the cost the advice warns about.
     *
     * This replaced a `render-bound` rule that asked the OPPOSITE question —
     * "is most of the time outside Livewire?" — and fired on all nine pages
     * measured, because on a Blade-first application it always is: the non-
     * Livewire share ran 66-87%. The share that actually varies is Livewire's
     * own, 13-34% on document pages against 52% on the board home, and that is
     * the number worth a label.
     */
    private const float LIVEWIRE_BOUND_RATIO = 0.40;

    /**
     * Heavy in the units the visitor pays: bytes ON THE WIRE, compressed.
     *
     * The old rule used 150 KB of uncompressed HTML and was wrong by roughly
     * nine times — a 288 KB page ships as 32 KB. 100 KB compressed is seven
     * round trips at the initial congestion window, which is a page worth
     * opening.
     */
    private const int PAYLOAD_HEAVY_BYTES = 100_000;

    private const int SNAPSHOT_HEAVY_BYTES = 4_096;

    private const float SNAPSHOT_HEAVY_RATIO = 0.03;

    /** Within this share of a budget, a page is worth watching before it goes over. */
    private const float WATCH_RATIO = 0.80;

    public function __construct(
        public float $wallMs,
        public float $dbMs,
        public float $livewireMs,
        public int $queries,
        public int $avoidableExecutions,
        public int $nPlusOneShapes,
        public int $responseBytes,
        public int $snapshotBytes,
        /** Uncompressed page bytes — the like-for-like denominator for the snapshot ratio. */
        public int $uncompressedBytes,
        public bool $hasChildHeavyComponent,
        public int $outboundCalls,
        public bool $uncacheable,
        public ?int $queryBudget,
        /*
         * Whether a budget verdict means anything for this measurement.
         *
         * FALSE for the sweeper, and that is not a convenience. Budgets are
         * measured against the seeded fixture, where the board holds a fixed
         * number of rows; the sweeper runs against the real imported database,
         * where the same page reads 9 queries instead of 5. Judging one by the
         * other would mark almost every page `query-heavy` and the label would
         * stop meaning anything. The gate owns budgets; the sweeper measures.
         */
        public bool $budgetsApply = true,
    ) {}

    /**
     * Every finding on this page. Empty means nothing, so `ok` fills it.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        $labels = [];

        if ($this->avoidableExecutions > 0) {
            $labels[] = 'repeated-query';
        }

        if ($this->nPlusOneShapes > 0) {
            $labels[] = 'n-plus-one';
        }

        if ($this->budgetsApply && $this->queryBudget !== null && $this->queries > $this->queryBudget) {
            $labels[] = 'query-heavy';
        }

        if ($this->outboundCalls > 0) {
            $labels[] = 'vendor-bound';
        }

        if ($this->uncacheable) {
            $labels[] = 'uncacheable';
        }

        if ($this->dbMs >= self::MATERIAL_MS && $this->ratioOfWall($this->dbMs) >= self::DB_BOUND_RATIO) {
            $labels[] = 'db-bound';
        }

        if ($this->livewireMs >= self::MATERIAL_MS && $this->ratioOfWall($this->livewireMs) >= self::LIVEWIRE_BOUND_RATIO) {
            $labels[] = 'livewire-bound';
        }

        if ($this->hasChildHeavyComponent) {
            $labels[] = 'child-heavy';
        }

        if ($this->responseBytes >= self::PAYLOAD_HEAVY_BYTES) {
            $labels[] = 'payload-heavy';
        }

        if ($this->isSnapshotHeavy()) {
            $labels[] = 'snapshot-heavy';
        }

        if ($this->budgetsApply && $this->queryBudget === null) {
            $labels[] = 'unbudgeted';
        }

        return $labels === [] ? ['ok'] : $labels;
    }

    /**
     * The labels that mean SOMETHING IS WRONG AND YOU CAN FIX IT.
     *
     * The others — `db-bound`, `livewire-bound`, `uncacheable` on a page that
     * carries a per-session token — describe where a request spends itself.
     * They are worth printing and they are not defects, and mixing the two was
     * making the report unreadable: a board with every duplicate query fixed
     * still showed five "findings", none of which anybody should act on. A
     * reader who cannot reach zero stops reading the number.
     *
     * @var list<string>
     */
    public const array DEFECTS = [
        'repeated-query',
        'n-plus-one',
        'query-heavy',
        'vendor-bound',
        'payload-heavy',
        'oversized-html',
        'snapshot-heavy',
        'child-heavy',
        'unbudgeted',
    ];

    public function isOk(): bool
    {
        return $this->labels() === ['ok'];
    }

    /**
     * @return list<string>
     */
    public function defects(): array
    {
        return array_values(array_intersect($this->labels(), self::DEFECTS));
    }

    /**
     * @return list<string>
     */
    public function characteristics(): array
    {
        return array_values(array_diff($this->labels(), [...self::DEFECTS, 'ok']));
    }

    public function hasDefect(): bool
    {
        return $this->defects() !== [];
    }

    public function status(): string
    {
        if ($this->queryBudget === null) {
            return self::STATUS_UNBUDGETED;
        }

        if ($this->queries > $this->queryBudget || $this->avoidableExecutions > 0) {
            return self::STATUS_OVER;
        }

        return $this->queries >= (int) ceil($this->queryBudget * self::WATCH_RATIO)
            ? self::STATUS_WATCH
            : self::STATUS_OK;
    }

    /**
     * A snapshot is heavy in absolute terms OR relative to the page it rides on.
     *
     * Both are needed. 6 KB on a 160 KB dashboard is 3.6% and matters because it
     * makes a round trip on every keystroke; the same 6 KB on a 1 MB export page
     * would not. The absolute floor catches a small page with a fat component.
     */
    private function isSnapshotHeavy(): bool
    {
        if ($this->snapshotBytes >= self::SNAPSHOT_HEAVY_BYTES) {
            return true;
        }

        // Both sides uncompressed. `responseBytes` is a gzip estimate and a
        // snapshot length is not, so dividing one by the other compares nothing.
        return $this->uncompressedBytes > 0
            && $this->snapshotBytes / $this->uncompressedBytes >= self::SNAPSHOT_HEAVY_RATIO;
    }

    private function ratioOfWall(float $part): float
    {
        return $this->wallMs > 0.0 ? max(0.0, $part) / $this->wallMs : 0.0;
    }
}
