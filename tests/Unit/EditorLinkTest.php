<?php

declare(strict_types=1);

use Olgun\PagePerformance\Support\EditorLink;

/*
 * A link that cannot be clicked is only text, and text with escapes in it is
 * worse than text. Both directions are asserted.
 */

it('wraps a location in an OSC 8 hyperlink for a known editor', function (): void {
    $rendered = (new EditorLink('phpstorm', '/app', true))->render('src/Thing.php:12');

    expect($rendered)->toContain("\e]8;;")
        ->and($rendered)->toContain('phpstorm://open?file=/app/src/Thing.php&line=12')
        // the visible text must still be the location, so a copy-paste works
        ->and($rendered)->toContain('src/Thing.php:12');
});

it('makes the path ABSOLUTE, because an editor has no working directory', function (): void {
    expect((new EditorLink('vscode', '/home/me/project', true))->render('app/X.php:3'))
        ->toContain('vscode://file//home/me/project/app/X.php:3');
});

it('prints plain text when the output is not a terminal', function (): void {
    expect((new EditorLink('phpstorm', '/app', false))->render('src/Thing.php:12'))
        ->toBe('src/Thing.php:12');
});

it('prints plain text when no editor is configured', function (): void {
    expect((new EditorLink(null, '/app', true))->render('src/Thing.php:12'))
        ->toBe('src/Thing.php:12');
});

it('prints plain text for an editor it does not know', function (): void {
    // Emitting a URL scheme nothing will answer is worse than emitting none.
    expect((new EditorLink('acme-edit', '/app', true))->render('src/Thing.php:12'))
        ->toBe('src/Thing.php:12');
});

it('says so when there is no location at all', function (): void {
    expect((new EditorLink('phpstorm', '/app', true))->render(null))->toBe('location unknown');
});

it('handles a location with no line number', function (): void {
    expect((new EditorLink('phpstorm', '/app', true))->render('src/Thing.php'))
        ->toContain('line=1');
});

it('does NOT claim a link is clickable in a terminal that cannot render one', function (): void {
    /*
     * macOS Terminal.app renders OSC 8 as nothing at all. Telling its users the
     * `where` column is clickable was a claim the tool never checked — and one
     * of them read it, tried, and reported that it was not.
     */
    expect(EditorLink::explain(false, 'phpstorm'))->toContain('--open=')
        ->and(EditorLink::explain(true, 'phpstorm'))->toContain('clickable')
        ->and(EditorLink::explain(true, ''))->toContain('No editor configured');
});

it('hands back a URL an opener can use', function (): void {
    // What `--open=N` passes to `open`, for terminals with no link support.
    expect((new EditorLink('phpstorm', '/app', false))->urlFor('src/Thing.php:12'))
        ->toBe('phpstorm://open?file=/app/src/Thing.php&line=12');
});
