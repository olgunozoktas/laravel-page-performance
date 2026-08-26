<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use Illuminate\Support\Facades\DB;
use Olgun\PagePerformance\Support\EditorLink;

/**
 * The measured pages, rendered so a reader knows what to open.
 *
 * MILLISECONDS ARE A COLUMN, NEVER THE SORT KEY. Rows are ordered by repeated
 * queries, then by database share, then by bytes, and only then by time. That
 * single rule is what stops the cold-process trap producing a confident wrong
 * ranking: the deterministic evidence decides the order and the clock is the
 * tiebreak of last resort.
 *
 * IT STATES ITS OWN CONDITIONS FIRST. A number that does not say what it
 * measured cannot be re-audited, and half the numbers here would be different
 * on another machine or another database. What was NOT measured is printed too,
 * because absence stated as a decision is the difference between a scope and a
 * blind spot.
 *
 * AN `ok` PAGE STILL PRINTS ITS NUMBERS. A healthy row and a row the tool
 * failed to measure must not look the same.
 */
final readonly class PageReport
{
    public const array COLUMNS = ['page', 'ms', 'spread', 'cold', 'db', 'lw', 'q', 'dup', 'snap', 'html', 'wire', 'diagnosis'];

    /**
     * @param  list<PageResult>  $results
     * @param  list<MeasurableRoute>  $skipped
     */
    public function __construct(
        private array $results,
        private array $skipped,
        private bool $livewireTimingAvailable,
        private string $filter = '',
    ) {}

    /**
     * @return array<string, string>
     */
    public function conditions(): array
    {
        $failures = count(array_filter($this->results, static fn (PageResult $r): bool => ! $r->measured()));

        return [
            'Mode' => sprintf(
                'in-process · %d warmup + %d measured · median reported · cold shown separately',
                config()->integer('page-performance.warmup', 1),
                count($this->results[0]->runs ?? []),
            ),
            'Database' => sprintf('%s "%s" — the REAL local data, not the test fixture', DB::getDefaultConnection(), DB::connection()->getDatabaseName()),
            'Runtime' => sprintf(
                'PHP %s · opcache %s · APP_DEBUG=%s · Livewire phase timing %s',
                PHP_VERSION,
                function_exists('opcache_get_status') && is_array(@opcache_get_status(false)) ? 'on' : 'off',
                config()->boolean('app.debug') ? 'true' : 'false',
                $this->livewireTimingAvailable ? 'ON' : 'OFF — '.LivewireProfile::unavailableReason(),
            ),
            'Coverage' => $this->filter === ''
                ? sprintf('%d measured · %d not requestable, each with a reason · %d could not be measured', count($this->results) - $failures, count($this->skipped), $failures)
                : sprintf('FILTERED to "%s" — %d measured. This is NOT a sweep of the application.', $this->filter, count($this->results) - $failures),
            'Budgets' => 'NOT evaluated here — they are calibrated on the seeded fixture, this reads live data',
            'Not measured' => 'network, TLS, browser render, opcache-warm production, concurrency, mutations',
        ];
    }

    /**
     * @return list<array<int, string>>
     */
    public function rows(): array
    {
        $sorted = $this->sorted();

        return array_map(function (PageResult $result): array {
            $run = $result->representative();

            if (! $run instanceof RequestMeasurement) {
                return [$result->route->name, '—', '—', '—', '—', '—', '—', '—', '—', '—', '—', 'COULD NOT MEASURE: '.$result->failure];
            }

            return [
                $result->route->name,
                sprintf('%.1f', $result->medianWallMs()),
                sprintf('%.0f%%', $result->spreadPercent()),
                sprintf('%.0f', $result->coldMs),
                sprintf('%.1f', $run->dbMs()),
                sprintf('%.1f', $run->livewireMs),
                (string) $run->queries->count(),
                (string) $run->queries->avoidableExecutions(),
                $this->kb($run->snapshots->totalBytes()),
                $this->kb($run->bytes),
                $this->kb($run->compressedBytes()),
                implode(' · ', $result->diagnosis()->labels()),
            ];
        }, $sorted);
    }

    public const array FINDING_COLUMNS = ['#', 'pages', 'finding', 'evidence', 'where'];

    /**
     * Every finding, GROUPED, worst page first.
     *
     * ONE DEFECT IS ONE ROW, however many pages carry it. A real sweep produced
     * 77 rows, and 28 of them were the same table-existence check from the same
     * line of the same shared-data class — one defect, printed once per page,
     * burying the six that were distinct. A report you have to de-duplicate by
     * eye is a report that gets skimmed.
     *
     * Grouping is by finding, evidence and location together, so two pages
     * repeating DIFFERENT queries stay two rows.
     *
     * @return list<array<int, string>>
     */
    public function findingRows(EditorLink $links): array
    {
        $groups = [];

        foreach ($this->sorted() as $result) {
            foreach ($this->findingsFor($result) as $finding) {
                $key = $finding['finding'].'|'.$finding['evidence'].'|'.$finding['where'];

                $groups[$key] ??= [...$finding, 'pages' => []];
                $groups[$key]['pages'][] = $result->route->name;
            }
        }

        $rows = [];
        $n = 0;

        foreach ($groups as $group) {
            $pages = $group['pages'];

            $rows[] = [
                (string) ++$n,
                count($pages) === 1 ? $pages[0] : sprintf('%d pages', count($pages)),
                $group['finding'],
                $group['evidence'],
                // An em dash for a finding that HAS no location, never
                // "location unknown" — that phrase means we looked and failed,
                // and a payload size has no line of code to point at.
                $group['where'] === '' ? '—' : $links->render($group['where']),
            ];
        }

        return $rows;
    }

    /**
     * The pages behind a grouped finding, for `--json` and for anyone who needs
     * the list rather than the count.
     *
     * @return array<string, list<string>>
     */
    public function findingPages(): array
    {
        $groups = [];

        foreach ($this->sorted() as $result) {
            foreach ($this->findingsFor($result) as $finding) {
                $groups[$finding['finding'].' · '.$finding['evidence']][] = $result->route->name;
            }
        }

        return $groups;
    }

    /**
     * @return list<array{finding: string, evidence: string, where: string}>
     */
    private function findingsFor(PageResult $result): array
    {
        $run = $result->representative();

        if (! $run instanceof RequestMeasurement || $result->diagnosis()->isOk()) {
            return [];
        }

        $labels = $result->diagnosis()->labels();
        $found = [];

        foreach ($run->queries->repeated() as $repeat) {
            $found[] = [
                'finding' => 'repeated-query',
                'evidence' => sprintf('x%d %s', $repeat['runs'], mb_strimwidth($repeat['sql'], 0, 58, '…')),
                'where' => $repeat['location'] ?? '',
            ];
        }

        foreach ($run->queries->nPlusOne() as $loop) {
            $found[] = [
                'finding' => 'n-plus-one',
                'evidence' => sprintf('%d distinct bindings · %s', $loop['distinct_bindings'], mb_strimwidth($loop['sql'], 0, 44, '…')),
                'where' => $loop['location'] ?? '',
            ];
        }

        if (in_array('vendor-bound', $labels, true)) {
            $found[] = [
                'finding' => 'vendor-bound',
                'evidence' => sprintf('outbound HTTP inside the render — %s', $run->outbound->summary()),
                'where' => '',
            ];
        }

        if (in_array('uncacheable', $labels, true)) {
            $found[] = [
                'finding' => 'uncacheable',
                'evidence' => 'Livewire set no-store, so no CDN or browser may hold this page',
                'where' => '',
            ];
        }

        if (in_array('db-bound', $labels, true)) {
            $slowest = $run->queries->slowest();
            $found[] = [
                'finding' => 'db-bound',
                'evidence' => sprintf('%.0f%% of the request is the database · slowest %.1f ms', $run->dbMs() / max($run->wallMs, 0.01) * 100, $slowest['ms'] ?? 0.0),
                'where' => $slowest['location'] ?? '',
            ];
        }

        if (in_array('livewire-bound', $labels, true)) {
            $found[] = [
                'finding' => 'livewire-bound',
                'evidence' => sprintf('%.0f%% of the request is Livewire', $run->livewireMs / max($run->wallMs, 0.01) * 100),
                'where' => '',
            ];
        }

        foreach ($run->components as $component) {
            if ($component->isChildHeavy()) {
                $found[] = [
                    'finding' => 'child-heavy',
                    'evidence' => sprintf('%s: %.0f%% of its render is one child', $component->name, $component->childMs() / max($component->renderMs(), 0.01) * 100),
                    'where' => '',
                ];
            }
        }

        if (in_array('payload-heavy', $labels, true) || in_array('oversized-html', $labels, true)) {
            $found[] = [
                'finding' => 'payload-heavy',
                'evidence' => sprintf('%s over the wire (%s uncompressed)', $this->kb($run->compressedBytes()), $this->kb($run->bytes)),
                'where' => '',
            ];
        }

        $heaviest = $run->snapshots->heaviest();

        if (in_array('snapshot-heavy', $labels, true) && $heaviest !== null) {
            $found[] = [
                'finding' => 'snapshot-heavy',
                'evidence' => sprintf('%s carries %s, sent up AND back per interaction', $heaviest['name'], $this->kb($heaviest['bytes'])),
                'where' => '',
            ];
        }

        if ($result->isNoisy()) {
            $found[] = [
                'finding' => 'noisy-measurement',
                'evidence' => sprintf('%.0f%% spread — this row\'s position is not trustworthy', $result->spreadPercent()),
                'where' => '',
            ];
        }

        return $found;
    }

    /** Nothing measured is NOT a pass — the command turns this into exit 2. */
    public function everythingMeasured(): bool
    {
        foreach ($this->results as $result) {
            if (! $result->measured()) {
                return false;
            }
        }

        return $this->results !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'conditions' => $this->conditions(),
            'columns' => self::COLUMNS,
            'rows' => $this->rows(),
            'finding_columns' => self::FINDING_COLUMNS,
            'findings' => $this->findingRows(new EditorLink(null, '', false)),
            'finding_pages' => $this->findingPages(),
            'skipped' => array_map(static fn (MeasurableRoute $r): array => ['route' => $r->name, 'reason' => $r->skipReason], $this->skipped),
        ];
    }

    /**
     * Worst first, by EVIDENCE rather than by clock.
     *
     * @return list<PageResult>
     */
    private function sorted(): array
    {
        $sorted = $this->results;

        usort($sorted, function (PageResult $a, PageResult $b): int {
            $runA = $a->representative();
            $runB = $b->representative();

            if (! $runA instanceof RequestMeasurement || ! $runB instanceof RequestMeasurement) {
                return $runB instanceof RequestMeasurement ? 1 : -1;
            }

            return [$runB->queries->avoidableExecutions(), $runB->dbMs(), $runB->bytes, $b->medianWallMs()]
                <=> [$runA->queries->avoidableExecutions(), $runA->dbMs(), $runA->bytes, $a->medianWallMs()];
        });

        return $sorted;
    }

    private function kb(int $bytes): string
    {
        return $bytes >= 1_024 ? sprintf('%.0fK', $bytes / 1_024) : (string) $bytes;
    }
}
