<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
final readonly class PageMeasurer
{
    public function __construct(
        private int $warmup = 1,
        private int $iterations = 5,
        private ?Authenticatable $actingAs = null,
    ) {}

    public function measure(MeasurableRoute $route): PageResult
    {
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

    private function once(string $path, LivewireProfile $profile): RequestMeasurement
    {
        $profile->reset();

        $records = [];

        DB::listen(function (QueryExecuted $query) use (&$records): void {
            $records[] = [
                'sql' => $query->sql,
                'bindings' => (string) json_encode($query->bindings),
                'ms' => $query->time,
                'location' => $this->callSite(),
            ];
        });

        $started = hrtime(true);
        $response = $this->send($path);
        $wallMs = (hrtime(true) - $started) / 1e6;

        // One hop only — see the class docblock.
        if ($response->isRedirection()) {
            $location = (string) $response->headers->get('Location');
            $next = parse_url($location, PHP_URL_PATH);

            if (is_string($next) && $next !== '' && $next !== $path) {
                $records = [];
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
            queries: QueryDigest::of($records),
            snapshots: SnapshotReader::read($html),
            components: $profile->timings(),
            bytes: strlen($html),
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
