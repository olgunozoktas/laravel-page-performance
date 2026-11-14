<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use InvalidArgumentException;

/**
 * The values that turn a route with parameters into one real address.
 *
 * A GUESSED VALUE IS NEVER USED. A route gets an address only when the host
 * names a value for every required parameter, in the config map
 * `page-performance.parameters` or with `--param`. A value that points at a
 * row that no longer exists answers 404, and the report already shows a
 * non-2xx as NOT THE PAGE, so a stale value is visible, never ranked.
 *
 * `--param` WINS OVER THE CONFIG for the same route and parameter, so one run
 * can try another value without an edit of the config file.
 */
final readonly class RouteParameters
{
    /**
     * @param  array<string, array<string, string>>  $values  route name => [parameter => value]
     */
    public function __construct(private array $values = []) {}

    /**
     * @param  list<string>  $options  each `route.name:parameter=value`
     */
    public static function fromConfig(array $options = []): self
    {
        $values = [];

        foreach (config()->array('page-performance.parameters', []) as $route => $parameters) {
            if (! is_string($route) || ! is_array($parameters)) {
                continue;
            }

            foreach ($parameters as $parameter => $value) {
                if (is_string($parameter) && (is_string($value) || is_int($value))) {
                    $values[$route][$parameter] = (string) $value;
                }
            }
        }

        foreach ($options as $option) {
            [$route, $parameter, $value] = self::parse($option);
            $values[$route][$parameter] = $value;
        }

        return new self($values);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public static function parse(string $option): array
    {
        if (preg_match('/^([^:=\s]+):([A-Za-z_]\w*)=(.+)$/', $option, $match) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The --param value "%s" is not in the form route.name:parameter=value.',
                $option,
            ));
        }

        return [$match[1], $match[2], $match[3]];
    }

    /**
     * The route with each `{parameter}` replaced, or the names of the required
     * parameters that have no value.
     *
     * An optional parameter (`{name?}`) without a value is left out of the
     * address, as the router itself does.
     *
     * @return array{uri: string, missing: list<string>}
     */
    public function fill(string $routeName, string $uri): array
    {
        $given = $this->values[$routeName] ?? [];
        $missing = [];

        $filled = preg_replace_callback(
            '/\{(\w+)(\?)?(?::\w+)?\}/',
            static function (array $match) use ($given, &$missing): string {
                $name = $match[1];

                if (isset($given[$name])) {
                    return rawurlencode($given[$name]);
                }

                if (($match[2] ?? '') !== '?') {
                    $missing[] = $name;
                }

                return '';
            },
            $uri,
        ) ?? $uri;

        $filled = preg_replace('#/{2,}#', '/', $filled) ?? $filled;

        return ['uri' => rtrim($filled, '/') === '' ? '/' : rtrim($filled, '/'), 'missing' => $missing];
    }
}
