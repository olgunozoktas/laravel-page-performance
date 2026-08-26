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
    public const array COLUMNS = ['page', 'ms', 'spread', 'cold', 'db', 'lw', 'q', 'dup', 'snap', 'html', 'diagnosis'];

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
                return [$result->route->name, '—', '—', '—', '—', '—', '—', '—', '—', '—', 'COULD NOT MEASURE: '.$result->failure];
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
                implode(' · ', $result->diagnosis()->labels()),
            ];
        }, $sorted);
    }

    public const array FINDING_COLUMNS = ['#', 'page', 'finding', 'evidence', 'where'];

    /**
     * EVERY finding, one per row, worst page first.
     *
     * A row per FINDING rather than a paragraph per page, because the question
     * being asked is "what is there to fix" and the answer is a list. The
     * `where` column is a clickable link wherever the terminal and the
     * configured editor both support it, and plain text everywhere else.
     *
     * @return list<array<int, string>>
     */
    public function findingRows(EditorLink $links): array
    {
        $rows = [];
        $n = 0;

        foreach ($this->sorted() as $result) {
            $run = $result->representative();

            if (! $run instanceof RequestMeasurement || $result->diagnosis()->isOk()) {
                continue;
            }

            $page = $result->route->name;
            $labels = $result->diagnosis()->labels();

            foreach ($run->queries->repeated() as $repeat) {
                $rows[] = [(string) ++$n, $page, 'repeated-query',
                    sprintf('x%d %s', $repeat['runs'], mb_strimwidth($repeat['sql'], 0, 58, '…')),
                    $links->render($repeat['location'])];
            }

            foreach ($run->queries->nPlusOne() as $loop) {
                $rows[] = [(string) ++$n, $page, 'n-plus-one',
                    sprintf('%d distinct bindings · %s', $loop['distinct_bindings'], mb_strimwidth($loop['sql'], 0, 44, '…')),
                    $links->render($loop['location'])];
            }

            if (in_array('db-bound', $labels, true)) {
                $slowest = $run->queries->slowest();
                $rows[] = [(string) ++$n, $page, 'db-bound',
                    sprintf('%.1f ms of %.1f ms in the database', $run->dbMs(), $run->wallMs),
                    $links->render($slowest['location'] ?? null)];
            }

            if (in_array('livewire-bound', $labels, true)) {
                $rows[] = [(string) ++$n, $page, 'livewire-bound',
                    sprintf('%.1f ms of %.1f ms in Livewire', $run->livewireMs, $run->wallMs), '—'];
            }

            foreach ($run->components as $component) {
                if ($component->isChildHeavy()) {
                    $rows[] = [(string) ++$n, $page, 'child-heavy',
                        sprintf('%s: %.1f ms of its %.1f ms render is a child', $component->name, $component->childMs(), $component->renderMs()), '—'];
                }
            }

            if (in_array('payload-heavy', $labels, true) || in_array('oversized-html', $labels, true)) {
                $rows[] = [(string) ++$n, $page, 'payload-heavy', sprintf('%s of HTML', $this->kb($run->bytes)), '—'];
            }

            $heaviest = $run->snapshots->heaviest();

            if (in_array('snapshot-heavy', $labels, true) && $heaviest !== null) {
                $rows[] = [(string) ++$n, $page, 'snapshot-heavy',
                    sprintf('%s carries %s, sent up AND back per interaction', $heaviest['name'], $this->kb($heaviest['bytes'])), '—'];
            }

            if ($result->spreadPercent() >= 25.0) {
                $rows[] = [(string) ++$n, $page, 'noisy-measurement',
                    sprintf('%.0f%% spread — this row\'s position is not trustworthy', $result->spreadPercent()), '—'];
            }
        }

        return $rows;
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
