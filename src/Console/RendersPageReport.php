<?php

declare(strict_types=1);

namespace Olgun\PagePerformance\Console;

use Olgun\PagePerformance\PageReport;
use Olgun\PagePerformance\Support\EditorLink;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * The terminal form of a report, shared by `perf:pages` and `perf:requests`.
 *
 * ONE RENDER FOR BOTH. A named request is read with the same table, the same
 * Defects and the same `--open=N` as a page, so a reader learns one report.
 * The command that uses it has the options `--open` and `--json`.
 */
trait RendersPageReport
{
    private function render(PageReport $report): void
    {
        foreach ($report->conditions() as $label => $value) {
            $this->components->twoColumnDetail(sprintf('<options=bold>%s</>', $label), $value);
        }

        // Before the table: a reader takes the times as the server's unless told otherwise.
        foreach ([$report->opcacheWarning(), $report->loadWarning()] as $warning) {
            if ($warning !== null) {
                // line(), not components->warn(): warn() wraps at the terminal width and breaks the command in two.
                $this->newLine();
                $this->line(sprintf('  <bg=yellow;fg=black> WARN </> %s', $warning));
            }
        }

        $this->newLine();
        $this->table($report->columns(), $report->rows());

        $links = EditorLink::fromConfig($this->supportsHyperlinks());
        $findings = $report->findingRows($links);
        $characteristics = $report->characteristicRows($links);

        $this->newLine();

        if ($findings === []) {
            $this->components->info('No defects. Nothing on these pages is worth fixing.');
        } else {
            $this->components->twoColumnDetail(
                '<options=bold>Defects</>',
                sprintf('%d — something is wrong and you can fix it', count($findings)),
            );
            $this->table(PageReport::FINDING_COLUMNS, $findings);
        }

        if ($characteristics !== []) {
            $this->newLine();
            $this->components->twoColumnDetail(
                '<options=bold>Characteristics</>',
                sprintf('%d — where the time goes. NOT defects.', count($characteristics)),
            );
            $this->table(PageReport::FINDING_COLUMNS, $characteristics);
        }

        $this->components->info(EditorLink::explain(
            EditorLink::terminalRenders() && $this->supportsHyperlinks(),
            config()->string('page-performance.editor', ''),
        ));

        $this->openRequestedFinding($report, $links, [...$findings, ...$characteristics]);
    }

    /**
     * `--open=N` — for the terminals that cannot make a link clickable.
     *
     * macOS Terminal.app is the common one: it renders OSC 8 as nothing at all,
     * so a report that only emitted escapes handed those users a column they
     * could look at and not use.
     *
     * @param  list<array<int, string>>  $rows
     */
    private function openRequestedFinding(PageReport $report, EditorLink $links, array $rows): void
    {
        $which = $this->option('open');

        if (! is_string($which) || $which === '') {
            return;
        }

        $index = (int) $which;
        $locations = $report->locations();

        if (! isset($locations[$index])) {
            $this->components->error(sprintf('No finding numbered %d has a location to open.', $index));

            return;
        }

        $url = $links->urlFor($locations[$index]);

        if ($url === null) {
            $this->components->error('No editor is configured. Set page-performance.editor.');

            return;
        }

        // `open` is macOS; `xdg-open` elsewhere. Neither is required for the
        // report itself, so a missing one is a warning and not a failure.
        $opener = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';

        exec(sprintf('%s %s 2>/dev/null', $opener, escapeshellarg($url)), $ignored, $status);

        $status === 0
            ? $this->components->info(sprintf('Opened %s', $locations[$index]))
            : $this->components->warn(sprintf('Could not open it. The location is %s', $locations[$index]));
    }

    /**
     * Whether emitting an OSC 8 hyperlink is safe here.
     *
     * Only into a terminal. Piped into a file, a pager or another command, the
     * escapes would sit in the output as control characters and stop a grep
     * from matching the very paths this column exists to hand over.
     */
    private function supportsHyperlinks(): bool
    {
        $output = $this->output->getOutput();

        if (! $output instanceof StreamOutput) {
            return false;
        }

        return stream_isatty($output->getStream());
    }

    /** Raise the memory limit to `page-performance.memory_limit`, never lower it (-1 and a higher limit are kept). */
    private function raiseMemoryLimit(): void
    {
        $floor = config('page-performance.memory_limit');

        if (! is_string($floor) || $floor === '') {
            return;
        }

        $current = ini_parse_quantity((string) ini_get('memory_limit'));

        if ($current !== -1 && $current < ini_parse_quantity($floor)) {
            ini_set('memory_limit', $floor);
        }
    }
}
