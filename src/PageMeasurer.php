<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Requests one page several times and reports what it costs.
 *
 * WARM FIRST, ALWAYS. The first request handled in a PHP process resolves
 * singletons, reads the schema and primes the permission cache. Measured here:
 * about 90 ms, on whichever page happens to go first. `/` read 150 ms first and
 * 42 ms on its third run. A sweep without a warmup ranks pages by visit order
 * and presents it as a ranking by cost.
 *
 * ONE REDIRECT IS FOLLOWED. `/dashboard` answers 302 to a workspace-scoped URL,
 * and the page worth measuring is the one after the hop. More than one hop is
 * not followed: a chain is a finding of its own, not something to measure past.
 *
 * IT NEVER WRITES. GET only, and the command refuses to run in production. The
 * query log records SHAPES and a location; bindings are hashed by
 * {@see QueryDigest} before anything is kept, because a binding is a payer's
 * name or email and this output reaches a report file and a terminal.
 */
/*
 * NOT `readonly`, and Rector will try to make it so again.
 *
 * It holds a mutable query buffer on purpose — see `listenOnce()`. A readonly
 * class cannot, which is how the buffer came to be a fresh closure capture per
 * request and how the whole command came to die of memory exhaustion.
 */
final class PageMeasurer
{
    /**
     * Queries seen since the buffer was last cleared.
     *
     * @var list<array{sql: string, bindings: string, ms: float, location: string|null}>
     */
    private array $records = [];

    private bool $listening = false;

    private OutboundCalls $outbound;

    public function __construct(
        private int $warmup = 1,
        private int $iterations = 5,
        private ?Authenticatable $actingAs = null,
    ) {}

    public function measure(MeasurableRoute $route): PageResult
    {
        $this->outbound ??= new OutboundCalls;

        $profile = new LivewireProfile;
        $profile->subscribe();

        try {
            $cold = 0.0;

            for ($i = 0; $i < max(1, $this->warmup); $i++) {
                $warm = $this->once($route->path(), $profile);
                $cold = $i === 0 ? $warm->wallMs : $cold;
            }

            $runs = [];

            for ($i = 0; $i < max(1, $this->iterations); $i++) {
                $runs[] = $this->once($route->path(), $profile);
            }

            return new PageResult($route, $cold, $runs);
        } catch (Throwable $throwable) {
            return PageResult::failed($route, Str::limit($throwable->getMessage(), 120));
        } finally {
            $profile->unsubscribe();
        }
    }

    /**
     * Register the query listener EXACTLY ONCE per measurer.
     *
     * `DB::listen()` has no counterpart that removes a listener. Registering
     * one per request looked harmless and was not: a full sweep is 26 pages by
     * 6 runs, so the process finished with 156 live listeners, every one still
     * appending to the array its own closure had captured. Growth is quadratic
     * in queries, and the bare `perf:pages` died with
     * `Allowed memory size of 134217728 bytes exhausted` while printing NOTHING
     * and exiting 255 — a PHP memory fatal cannot be caught, so the only fix is
     * not to reach it.
     *
     * One listener, one buffer, cleared per run.
     */
    private function listenOnce(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        /*
         * Outbound HTTP, on the same once-only terms and for the same reason.
         *
         * This is the costliest thing a render can do: a query is as slow as
         * your database, a third party's API is as slow as somebody else's
         * afternoon. Only Laravel's own client is visible here — a raw curl
         * handle or a vendor SDK with its own Guzzle goes unseen, so the label
         * firing is proof and its silence is not.
         */
        Event::listen(RequestSending::class, function (RequestSending $event): void {
            $this->outbound->record($event->request->url());
        });

        DB::listen(function (QueryExecuted $query): void {
            $this->records[] = [
                'sql' => $query->sql,
                'bindings' => (string) json_encode($query->bindings),
                'ms' => $query->time,
                'location' => $this->callSite(),
            ];
        });
    }

    private function once(string $path, LivewireProfile $profile): RequestMeasurement
    {
        $profile->reset();
        $this->listenOnce();
        $this->records = [];
        $this->outbound->reset();

        $started = hrtime(true);
        $response = $this->send($path);
        $wallMs = (hrtime(true) - $started) / 1e6;

        // One hop only — see the class docblock.
        if ($response->isRedirection()) {
            $location = (string) $response->headers->get('Location');
            $next = parse_url($location, PHP_URL_PATH);

            if (is_string($next) && $next !== '' && $next !== $path) {
                $this->records = [];
                $profile->reset();
                $started = hrtime(true);
                $response = $this->send($next);
                $wallMs = (hrtime(true) - $started) / 1e6;
            }
        }

        $body = $response->getContent();
        $html = is_string($body) ? $body : '';

        return new RequestMeasurement(
            status: $response->getStatusCode(),
            wallMs: $wallMs,
            livewireMs: $profile->livewireMs(),
            queries: QueryDigest::of($this->records),
            snapshots: SnapshotReader::read($html),
            components: $profile->timings(),
            bytes: strlen($html),
            outbound: $this->outbound,
            cacheControl: (string) $response->headers->get('Cache-Control'),
            html: $html,
        );
    }

    private function send(string $path): Response
    {
        $request = Request::create($path, 'GET');

        if ($this->actingAs instanceof Authenticatable) {
            Auth::guard('web')->setUser($this->actingAs);

            $session = resolve(SessionManager::class)->driver();

            // The manager types its driver as mixed. A request without a real
            // session simply measures the guest page, which is a wrong answer
            // rather than a missing one, so this refuses instead.
            throw_unless($session instanceof Session, RuntimeException::class, 'The session driver is not a session, so an authenticated page cannot be measured.');

            $session->setId(Str::random(40));
            $session->start();
            $session->put(Auth::getName(), $this->actingAs->getAuthIdentifier());
            $request->setLaravelSession($session);
        }

        return resolve(Kernel::class)->handle($request);
    }

    /**
     * The first frame in this application, so a finding names a file to open.
     *
     * Vendor frames are skipped because "it happened inside Eloquent" is true of
     * every query and tells nobody anything.
     */
    private function callSite(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = $frame['file'] ?? null;
            if (! is_string($file)) {
                continue;
            }
            if (str_contains($file, '/vendor/')) {
                continue;
            }
            if (str_contains($file, '/Performance/')) {
                continue;
            }

            return str_replace(base_path().'/', '', $file).':'.($frame['line'] ?? 0);
        }

        return null;
    }
}
