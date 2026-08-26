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

    public static function fromConfig(bool $supported): self
    {
        $editor = config()->string('page-performance.editor', '');

        return new self($editor === '' ? null : $editor, base_path(), $supported);
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
        if (! $this->supported || $this->editor === null) {
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
