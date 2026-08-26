<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * Outbound HTTP made while a page was rendering.
 *
 * WHY THIS IS THE MOST IMPORTANT THING THE TOOL LOOKS FOR. Every other cost on
 * a page has a ceiling you own: a query is as slow as your database, a render is
 * as slow as your Blade. A third party's API is as slow as somebody else's
 * afternoon, and it is the one number that can turn a 40 ms page into a 30 s
 * one without a single line of your code changing. A real console screen doing
 * exactly this — calling a payment vendor inside its render, across eight
 * pages — is what proved the label was worth having.
 *
 * IT RECORDS THE HOST, NEVER THE URL. A URL carries API keys in query strings,
 * customer ids in paths and tokens in both, and this output reaches a report
 * file, a terminal and an agent transcript. The host answers "who is this page
 * waiting on", which is the entire question. Same rule as the query bindings.
 *
 * WHAT IT CANNOT SEE, stated so nobody reads silence as absence: it listens for
 * Laravel's own HTTP client events. A raw curl handle, a vendor SDK with its own
 * Guzzle instance, or a socket opened by hand goes unseen. `vendor-bound` firing
 * is proof; it NOT firing is not proof of the opposite.
 */
final class OutboundCalls
{
    /** @var array<string, int> host => times called */
    private array $hosts = [];

    public function record(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        $key = is_string($host) && $host !== '' ? $host : 'unknown host';

        $this->hosts[$key] = ($this->hosts[$key] ?? 0) + 1;
    }

    public function reset(): void
    {
        $this->hosts = [];
    }

    public function count(): int
    {
        return array_sum($this->hosts);
    }

    /**
     * @return array<string, int>
     */
    public function hosts(): array
    {
        arsort($this->hosts);

        return $this->hosts;
    }

    /** `stripe.com x2 · api.example.test x1`, for the finding row. */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->hosts() as $host => $times) {
            $parts[] = sprintf('%s x%d', $host, $times);
        }

        return implode(' · ', $parts);
    }
}
