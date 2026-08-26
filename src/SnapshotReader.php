<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use JsonException;

/**
 * What each Livewire component costs in payload, read straight from the HTML.
 *
 * THIS IS THE ONE METRIC THAT SURVIVES PRODUCTION. Livewire's `profile` event —
 * the source of every timing this package reports — is fired only when
 * `config('app.debug')` is true, so the phase breakdown is local-only and always
 * will be. A snapshot is different: it is an ATTRIBUTE in the response, present
 * on every page in every environment, so this needs no listener, no debug flag
 * and no instrumentation of any kind.
 *
 * WHY PAYLOAD IS THE RIGHT THING TO WATCH ON THIS BOARD. Livewire ships the
 * whole snapshot up AND back on every round trip. Measured here: `console-search`
 * carries 5,948 bytes and is mounted by `app-shell` on every authenticated page,
 * with `wire:model.live.debounce.150ms` on its query field — so each keystroke
 * moves about 12 KB to filter an array the browser already holds. No timing
 * column would have found that; the byte count names it immediately.
 *
 * IT READS THE MEMO AND NOTHING ELSE. `memo.name` and `memo.id` identify the
 * component; `data` is measured by LENGTH and never inspected, because a public
 * property may legitimately hold the viewer's own input and this output reaches
 * a report file and a terminal. See `.claude/skills/board-privacy`.
 *
 * A SNAPSHOT IT CANNOT PARSE IS COUNTED, NOT DROPPED. A reader that silently
 * skipped one would report a lighter page than the one that shipped, and the
 * number would look fine.
 */
final readonly class SnapshotReader
{
    /**
     * @param  list<array{name: string, id: string, bytes: int}>  $components
     */
    private function __construct(
        public array $components,
        public int $unreadable,
    ) {}

    public static function read(string $html): self
    {
        $quote = chr(34);

        preg_match_all(
            sprintf('/wire:snapshot=%s([^%s]*)%s/', $quote, $quote, $quote),
            $html,
            $matches,
        );

        $components = [];
        $unreadable = 0;

        foreach ($matches[1] as $encoded) {
            $decoded = html_entity_decode($encoded, ENT_QUOTES);
            $memo = self::memo($decoded);

            if ($memo === null) {
                $unreadable++;

                continue;
            }

            $components[] = ['name' => $memo[0], 'id' => $memo[1], 'bytes' => strlen($decoded)];
        }

        return new self($components, $unreadable);
    }

    public function count(): int
    {
        return count($this->components);
    }

    public function totalBytes(): int
    {
        return array_sum(array_column($this->components, 'bytes'));
    }

    /** Every mount of one component name, summed — a component can appear twice on a page. */
    public function bytesFor(string $name): int
    {
        $matching = array_filter($this->components, static fn (array $c): bool => $c['name'] === $name);

        return array_sum(array_column($matching, 'bytes'));
    }

    /**
     * @return array{name: string, id: string, bytes: int}|null
     */
    public function heaviest(): ?array
    {
        $heaviest = null;

        foreach ($this->components as $component) {
            if ($heaviest === null || $component['bytes'] > $heaviest['bytes']) {
                $heaviest = $component;
            }
        }

        return $heaviest;
    }

    /**
     * Component id => name, for naming the spans the profile listener collects.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        return array_column($this->components, 'name', 'id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'components' => $this->count(),
            'snapshot_bytes' => $this->totalBytes(),
            'unreadable' => $this->unreadable,
            'heaviest' => $this->heaviest(),
        ];
    }

    /**
     * @return array{0: string, 1: string}|null [name, id]
     */
    private static function memo(string $json): ?array
    {
        try {
            $snapshot = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($snapshot) || ! is_array($snapshot['memo'] ?? null)) {
            return null;
        }

        $name = $snapshot['memo']['name'] ?? null;
        $id = $snapshot['memo']['id'] ?? null;

        return is_string($name) && is_string($id) ? [$name, $id] : null;
    }
}
