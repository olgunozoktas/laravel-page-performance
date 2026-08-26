<?php

declare(strict_types=1);

namespace Olgun\PagePerformance\Support;

/**
 * Turns `path/to/File.php:88` into something you can click.
 *
 * OSC 8 IS THE MECHANISM, and it is a terminal escape rather than anything this
 * package invents: `ESC ] 8 ;; <url> ESC \ <text> ESC ] 8 ;; ESC \`. iTerm2,
 * WezTerm, Ghostty, Kitty and recent GNOME Terminal render it as a hyperlink.
 * A terminal that does not understand it prints the TEXT and drops the escape,
 * so the worst case is exactly the output we had before.
 *
 * IT DEGRADES ON PURPOSE, in three places:
 *
 *   not a TTY        piping to a file or a pager must not embed escapes, or the
 *                    file fills with control characters and greps stop matching
 *   no editor set    `editor => null` prints plain text
 *   unknown editor   same — an unrecognised name is not a reason to emit a URL
 *                    scheme nothing will answer
 *
 * The path is made ABSOLUTE before it becomes a URL. An editor opening a link
 * has no idea what the terminal's working directory was.
 */
final readonly class EditorLink
{
    /**
     * Editor name => the URL its handler answers.
     *
     * `%p` is the absolute path, `%l` the line.
     */
    private const array SCHEMES = [
        'phpstorm' => 'phpstorm://open?file=%p&line=%l',
        'idea' => 'idea://open?file=%p&line=%l',
        'vscode' => 'vscode://file/%p:%l',
        'cursor' => 'cursor://file/%p:%l',
        'sublime' => 'subl://open?url=file://%p&line=%l',
        'textmate' => 'txmt://open?url=file://%p&line=%l',
        'zed' => 'zed://file/%p:%l',
        'file' => 'file://%p',
    ];

    public function __construct(
        private ?string $editor,
        private string $basePath,
        private bool $supported,
    ) {}

    /**
     * Terminals known to render OSC 8. macOS Terminal.app is NOT one of them.
     *
     * An allow-list, because the failure is silent in the wrong direction: a
     * terminal that does not understand the escape prints the text and drops
     * the link, and the reader is told a column is clickable when it is not.
     */
    private const array HYPERLINK_TERMINALS = [
        'iTerm.app', 'WezTerm', 'ghostty', 'vscode', 'Hyper', 'Tabby', 'rio', 'kitty',
    ];

    public static function fromConfig(bool $isTerminal): self
    {
        $editor = config()->string('page-performance.editor', '');

        return new self($editor === '' ? null : $editor, base_path(), $isTerminal && self::terminalRenders());
    }

    /**
     * Will THIS terminal turn an OSC 8 escape into something clickable?
     *
     * `page-performance.hyperlinks` overrides it — `always` for a terminal not
     * on the list, `never` to switch the escapes off entirely.
     */
    public static function terminalRenders(): bool
    {
        $setting = config()->string('page-performance.hyperlinks', 'auto');

        if ($setting === 'always') {
            return true;
        }

        if ($setting === 'never') {
            return false;
        }

        $program = (string) getenv('TERM_PROGRAM');
        $term = (string) getenv('TERM');

        return in_array($program, self::HYPERLINK_TERMINALS, true) || str_contains($term, 'kitty');
    }

    /** What to tell the reader, given what this terminal can actually do. */
    public static function explain(bool $rendering, ?string $editor): string
    {
        if ($editor === null || $editor === '') {
            return 'No editor configured, so `where` is plain text. Set page-performance.editor.';
        }

        if ($rendering) {
            return sprintf('The `where` column is clickable and opens in %s.', $editor);
        }

        return sprintf(
            'This terminal (%s) does not render clickable links. Use `--open=N` to open finding N in %s.',
            getenv('TERM_PROGRAM') ?: 'unknown',
            $editor,
        );
    }

    /**
     * The URL for one location, so `--open=N` can hand it to the OS.
     *
     * Deliberately NOT gated on terminal support: opening a file in an editor
     * has nothing to do with whether the terminal can draw a hyperlink, and
     * gating it there is what made this method return null in exactly the
     * terminals it exists to serve.
     */
    public function urlFor(string $location): ?string
    {
        return $this->buildUrl($location);
    }

    /**
     * @param  string|null  $location  as `relative/path.php:88`
     */
    public function render(?string $location): string
    {
        if ($location === null || $location === '') {
            return 'location unknown';
        }

        $url = $this->url($location);

        if ($url === null) {
            return $location;
        }

        // ESC ] 8 ;; url ESC \  text  ESC ] 8 ;; ESC \
        return "\e]8;;".$url."\e\\".$location."\e]8;;\e\\";
    }

    private function url(string $location): ?string
    {
        // The escape is only worth emitting where a terminal will render it.
        return $this->supported ? $this->buildUrl($location) : null;
    }

    private function buildUrl(string $location): ?string
    {
        if ($this->editor === null) {
            return null;
        }

        $scheme = self::SCHEMES[$this->editor] ?? null;

        if ($scheme === null) {
            return null;
        }

        $line = '1';
        $path = $location;

        if (preg_match('/^(.*):(\d+)$/', $location, $matches) === 1) {
            $path = $matches[1];
            $line = $matches[2];
        }

        $absolute = str_starts_with($path, '/') ? $path : rtrim($this->basePath, '/').'/'.$path;

        return str_replace(['%p', '%l'], [$absolute, $line], $scheme);
    }
}
