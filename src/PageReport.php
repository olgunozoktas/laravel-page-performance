<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use Illuminate\Support\Facades\DB;

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

    /**
     * One block per page worth acting on, each ending in something runnable.
     *
     * @return list<string>
     */
    public function findings(): array
    {
        $blocks = [];

        foreach ($this->sorted() as $result) {
            $run = $result->representative();
            if (! $run instanceof RequestMeasurement) {
                continue;
            }
            if ($result->diagnosis()->isOk()) {
                continue;
            }

            $lines = [sprintf('<options=bold>%s</> %s   %s', $result->route->name, $result->route->path(), implode(' · ', $result->diagnosis()->labels()))];

            foreach ($run->queries->repeated() as $repeat) {
                $lines[] = sprintf('    x%-3d %s', $repeat['runs'], mb_strimwidth($repeat['sql'], 0, 96, '…'));
                $lines[] = sprintf('         %s', $repeat['location'] ?? 'location unknown');
            }

            foreach ($run->components as $component) {
                if ($component->isChildHeavy()) {
                    $lines[] = sprintf('    %s spends %.2f ms of its %.2f ms render inside a child', $component->name, $component->childMs(), $component->renderMs());
                }
            }

            $heaviest = $run->snapshots->heaviest();

            if ($heaviest !== null && $heaviest['bytes'] >= 4_096) {
                $lines[] = sprintf('    %s carries a %s snapshot, sent up AND back on every interaction', $heaviest['name'], $this->kb($heaviest['bytes']));
            }

            if ($result->spreadPercent() >= 25.0) {
                $lines[] = sprintf('    spread %.0f%% — these runs disagreed enough that the position is not trustworthy', $result->spreadPercent());
            }

            $blocks[] = implode(PHP_EOL, $lines);
        }

        return $blocks;
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
