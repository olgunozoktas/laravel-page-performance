<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use InvalidArgumentException;

/**
 * One request to send: a name, a method, a path, headers and a JSON body.
 *
 * A PAGE IS A GET WITH NOTHING ELSE. `perf:pages` sends each of its routes
 * through `page()`, so the two commands share one send path and a page is sent
 * exactly as before: no header, no body.
 *
 * A NAMED REQUEST COMES FROM `page-performance.requests`. A `{fixture.key}`
 * text in its path, in a header value or in a body string takes the value that
 * the host's {@see RequestFixture} made. A body string that is ONLY the
 * placeholder keeps the type of the value, so an id stays an integer.
 */
final readonly class MeasurableRequest
{
    /** The methods a named request can use. */
    public const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private const string PLACEHOLDER = '/\{fixture\.([A-Za-z0-9_-]+)\}/';

    /**
     * @param  array<string, string>  $headers
     * @param  array<array-key, mixed>|null  $json
     */
    public function __construct(
        public string $name,
        public string $method,
        public string $path,
        public array $headers = [],
        public ?array $json = null,
    ) {}

    public static function page(string $name, string $path): self
    {
        return new self($name, 'GET', $path);
    }

    /**
     * Every named request of `page-performance.requests`, in config order.
     *
     * @return list<self>
     *
     * @throws InvalidArgumentException when an entry has no path, an unknown method or a header that is not text
     */
    public static function allFromConfig(): array
    {
        /** @var array<array-key, mixed> $entries */
        $entries = config()->array('page-performance.requests', []);

        $requests = [];

        foreach ($entries as $name => $entry) {
            if (! is_string($name) || ! is_array($entry)) {
                throw new InvalidArgumentException('Each entry of page-performance.requests is \'name\' => [\'method\' => …, \'path\' => …].');
            }

            /** @var array<array-key, mixed> $entry */
            $requests[] = self::fromConfig($name, $entry);
        }

        return $requests;
    }

    /**
     * One entry of `page-performance.requests`.
     *
     * An `Accept: application/json` header is added when the entry names none,
     * and a `Content-Type: application/json` header when it has a body: without
     * them an API answers a validation error with a redirect, which measures the
     * redirect.
     *
     * @param  array<array-key, mixed>  $entry
     *
     * @throws InvalidArgumentException
     */
    public static function fromConfig(string $name, array $entry): self
    {
        $method = strtoupper(is_string($entry['method'] ?? null) ? $entry['method'] : 'GET');

        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException(sprintf('Request "%s" has the method "%s". Use one of %s.', $name, $method, implode(', ', self::METHODS)));
        }

        $path = $entry['path'] ?? null;

        if (! is_string($path) || $path === '') {
            throw new InvalidArgumentException(sprintf('Request "%s" has no path.', $name));
        }

        $headers = [];

        foreach (is_array($entry['headers'] ?? null) ? $entry['headers'] : [] as $header => $value) {
            if (! is_string($header) || ! is_string($value)) {
                throw new InvalidArgumentException(sprintf('Request "%s" has a header that is not \'Name\' => \'text\'.', $name));
            }

            $headers[$header] = $value;
        }

        $json = $entry['json'] ?? null;

        if ($json !== null && ! is_array($json)) {
            throw new InvalidArgumentException(sprintf('Request "%s" has a json body that is not an array.', $name));
        }

        if (self::header($headers, 'Accept') === null) {
            $headers['Accept'] = 'application/json';
        }

        if ($json !== null && self::header($headers, 'Content-Type') === null) {
            $headers['Content-Type'] = 'application/json';
        }

        return new self($name, $method, '/'.ltrim($path, '/'), $headers, $json);
    }

    /**
     * The same request with each `{fixture.key}` replaced by its value.
     *
     * @param  array<string, string|int|float|bool>  $values
     *
     * @throws InvalidArgumentException when a placeholder names a key that the fixture did not make
     */
    public function resolve(array $values): self
    {
        $headers = [];

        foreach ($this->headers as $header => $value) {
            $headers[$header] = $this->interpolate($value, $values);
        }

        return new self(
            $this->name,
            $this->method,
            $this->interpolate($this->path, $values),
            $headers,
            $this->json === null ? null : $this->resolveBody($this->json, $values),
        );
    }

    /** The same request at another path: the one redirect hop that a GET follows. */
    public function withPath(string $path): self
    {
        return new self($this->name, $this->method, $path, $this->headers, $this->json);
    }

    /** The row of the report: a named request shows its name. */
    public function route(): MeasurableRoute
    {
        return MeasurableRoute::measurable($this->name, $this->path);
    }

    /**
     * The headers as the server keys that `Request::create()` reads.
     *
     * @return array<string, string>
     */
    public function server(): array
    {
        $server = [];

        foreach ($this->headers as $header => $value) {
            $key = strtoupper(str_replace('-', '_', $header));

            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }

    public function content(): ?string
    {
        return $this->json === null ? null : json_encode($this->json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @param  array<string, string|int|float|bool>  $values
     * @return array<array-key, mixed>
     */
    private function resolveBody(array $body, array $values): array
    {
        foreach ($body as $key => $value) {
            if (is_array($value)) {
                $body[$key] = $this->resolveBody($value, $values);
            } elseif (is_string($value) && preg_match('/^'.trim(self::PLACEHOLDER, '/').'$/', $value, $match) === 1) {
                $body[$key] = $this->value($match[1], $values);
            } elseif (is_string($value)) {
                $body[$key] = $this->interpolate($value, $values);
            }
        }

        return $body;
    }

    /**
     * @param  array<string, string|int|float|bool>  $values
     */
    private function interpolate(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $match): string => (string) $this->value($match[1], $values),
            $text,
        );
    }

    /**
     * @param  array<string, string|int|float|bool>  $values
     */
    private function value(string $key, array $values): string|int|float|bool
    {
        if (! array_key_exists($key, $values)) {
            throw new InvalidArgumentException(sprintf('Request "%s" names {fixture.%s}, and the fixture made no value "%s".', $this->name, $key, $key));
        }

        return $values[$key];
    }
}
