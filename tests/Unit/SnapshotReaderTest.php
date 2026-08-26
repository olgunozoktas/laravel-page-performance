<?php

declare(strict_types=1);

use Olgun\PagePerformance\SnapshotReader;

/*
 * Fixtures are BUILT here, never pasted from a live page. A snapshot carries
 * whatever the component's public properties held, and a pasted one would put a
 * real board's data into the test suite forever. Everything below is invented.
 */

function snapshotAttribute(string $name, string $id, string $data = '[]'): string
{
    $json = sprintf('{"data":%s,"memo":{"id":"%s","name":"%s","path":"\/"}}', $data, $id, $name);

    return sprintf('wire:snapshot="%s"', htmlspecialchars($json, ENT_QUOTES));
}

it('finds every component and sizes each one', function (): void {
    $html = '<div '.snapshotAttribute('legacy-page', 'aaa').'><span '
        .snapshotAttribute('console-search', 'bbb', '{"query":""}').'></span></div>';

    $reader = SnapshotReader::read($html);

    expect($reader->count())->toBe(2)
        ->and($reader->names())->toBe(['aaa' => 'legacy-page', 'bbb' => 'console-search'])
        ->and($reader->totalBytes())->toBeGreaterThan(0)
        ->and($reader->unreadable)->toBe(0);
});

it('names the heaviest component, which is the whole point', function (): void {
    $html = snapshotAttribute('legacy-page', 'aaa')
        .snapshotAttribute('console-search', 'bbb', '{"navigation":"'.str_repeat('x', 4_000).'"}');

    $heaviest = SnapshotReader::read($html)->heaviest();

    expect($heaviest)->not->toBeNull()
        ->and($heaviest['name'])->toBe('console-search')
        ->and($heaviest['bytes'])->toBeGreaterThan(4_000);
});

it('sums two mounts of the same component', function (): void {
    $html = snapshotAttribute('board.promotion-form', 'aaa').snapshotAttribute('board.promotion-form', 'bbb');

    $reader = SnapshotReader::read($html);

    expect($reader->count())->toBe(2)
        ->and($reader->bytesFor('board.promotion-form'))->toBe($reader->totalBytes());
});

it('COUNTS a snapshot it cannot read instead of dropping it', function (): void {
    // Dropping one would report a lighter page than the one that shipped, and
    // the number would look entirely reasonable.
    $html = snapshotAttribute('legacy-page', 'aaa').'<div wire:snapshot="{not json at all">';

    $reader = SnapshotReader::read($html);

    expect($reader->count())->toBe(1)
        ->and($reader->unreadable)->toBe(1);
});

it('reports zero for a page with no Livewire on it at all', function (): void {
    $reader = SnapshotReader::read('<html><body><p>A plain Blade page.</p></body></html>');

    expect($reader->count())->toBe(0)
        ->and($reader->totalBytes())->toBe(0)
        ->and($reader->unreadable)->toBe(0)
        ->and($reader->heaviest())->toBeNull();
});

it('does not read a component data value, only its length', function (): void {
    $html = snapshotAttribute('board.promotion-form', 'aaa', '{"email":"payer@example.test"}');

    $rendered = json_encode(SnapshotReader::read($html)->toArray(), JSON_THROW_ON_ERROR);

    expect($rendered)->not->toContain('payer@example.test');
});
