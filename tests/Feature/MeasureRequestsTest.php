<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Olgun\PagePerformance\RequestFixture;

/*
 * `perf:requests`, against routes and a fixture that this file defines.
 *
 * Each guard is proved able to FIRE: a write that would stay, a call that
 * would leave, a budget that would not be judged. A guard that is never seen
 * to fire cannot be told from a missing one.
 */

final class NotesFixture implements RequestFixture
{
    public static int $tornDown = 0;

    public function setUp(): array
    {
        $id = DB::table('notes')->insertGetId(['body' => 'made by the fixture']);

        return ['token' => 'fixture-token', 'note_id' => (int) $id];
    }

    public function tearDown(): void
    {
        self::$tornDown++;
    }
}

final class SeenInRequests
{
    /** @var list<string> */
    public static array $seen = [];
}

/**
 * @param  array<string, mixed>  $options
 * @return array{exit: int, report: array<string, mixed>}
 */
function measureRequests(array $options = []): array
{
    $exit = Artisan::call('perf:requests', ['--json' => true, '--repeat' => 1, ...$options]);

    /** @var array<string, mixed> $report */
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return ['exit' => $exit, 'report' => $report];
}

/**
 * @param  array<string, mixed>  $report
 * @return array<int, string>
 */
function requestRow(array $report, string $name): array
{
    /** @var list<array<int, string>> $rows */
    $rows = $report['rows'];

    foreach ($rows as $row) {
        if ($row[0] === $name) {
            return $row;
        }
    }

    throw new RuntimeException(sprintf('No row named %s.', $name));
}

beforeEach(function (): void {
    NotesFixture::$tornDown = 0;
    SeenInRequests::$seen = [];

    Schema::create('notes', function ($table): void {
        $table->id();
        $table->string('body');
    });

    Route::post('/notes', function (Request $request) {
        DB::table('notes')->insert(['body' => (string) $request->json('body')]);

        return response()->json(['stored' => true], 201);
    });

    Route::post('/keyed', function (Request $request) {
        abort_unless($request->bearerToken() === 'fixture-token', 401);
        abort_unless(is_int($request->json('note_id')), 422);
        abort_unless(DB::table('notes')->where('id', $request->json('note_id'))->exists(), 404);

        return response()->json(['found' => true]);
    });

    Route::post('/calls-out', function () {
        try {
            Http::get('https://api.example.test/ping');
        } catch (Throwable $refused) {
            SeenInRequests::$seen[] = $refused::class;

            throw $refused;
        }

        return response()->json(['called' => true]);
    });

    Route::post('/mails', function () {
        SeenInRequests::$seen[] = (string) config('mail.default');
        SeenInRequests::$seen[] = (string) config('queue.default');
        Mail::raw('A measured mail.', fn ($message) => $message->to('reader@example.test'));

        return response()->noContent();
    });

    Route::post('/notes/counted', function () {
        DB::table('notes')->insert(['body' => 'one more']);

        // The fixture's row and this run's row: a row of an earlier run is a leak.
        abort_unless(DB::table('notes')->count() === 2, 409);

        return response()->json(['stored' => true], 201);
    });

    Auth::viaRequest('test-token', fn (Request $request): ?GenericUser => $request->bearerToken() === null
        ? null
        : new GenericUser(['id' => $request->bearerToken()]));
    config()->set('auth.guards.test-token', ['driver' => 'test-token']);

    Route::get('/whoami', function (Request $request) {
        abort_unless(Auth::guard('test-token')->id() === $request->header('X-Expect'), 403);

        return response()->json(['me' => true]);
    });

    Route::get('/many', function () {
        DB::select('select 1 as a');
        DB::select('select 2 as b');
        DB::select('select 3 as c');

        return response()->json(['many' => true]);
    });

    config()->set('page-performance.requests', [
        'notes.store' => ['method' => 'POST', 'path' => '/notes', 'json' => ['body' => 'a measured note']],
        'notes.keyed' => [
            'method' => 'POST',
            'path' => '/keyed',
            'headers' => ['Authorization' => 'Bearer {fixture.token}'],
            'json' => ['note_id' => '{fixture.note_id}'],
        ],
    ]);
    config()->set('page-performance.fixture', NotesFixture::class);
});

it('measures a named POST with a JSON body: its status and its query count', function (): void {
    $run = measureRequests(['--only' => 'notes.store']);

    $row = requestRow($run['report'], 'notes.store');

    expect($run['exit'])->toBe(0)
        ->and($row[1])->toBe('201')
        ->and($row[7])->toBe('1')
        ->and($run['report']['columns'][0])->toBe('request');
});

it('leaves no row after the command: each run and the fixture roll back', function (): void {
    $run = measureRequests();

    expect($run['exit'])->toBe(0)
        ->and(requestRow($run['report'], 'notes.store')[1])->toBe('201')
        ->and(DB::table('notes')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(NotesFixture::$tornDown)->toBe(1);
});

it('starts each run from the same rows: a run does not see the row of the run before', function (): void {
    config()->set('page-performance.requests', ['notes.counted' => ['method' => 'POST', 'path' => '/notes/counted']]);

    $run = measureRequests(['--repeat' => 2]);

    expect(requestRow($run['report'], 'notes.counted')[1])->toBe('201');
});

it('measures each request as its own key: a guard does not keep the user of the request before', function (): void {
    config()->set('page-performance.fixture', null);
    config()->set('page-performance.requests', [
        'me.first' => ['path' => '/whoami', 'headers' => ['Authorization' => 'Bearer first', 'X-Expect' => 'first']],
        'me.second' => ['path' => '/whoami', 'headers' => ['Authorization' => 'Bearer second', 'X-Expect' => 'second']],
    ]);

    $run = measureRequests();

    expect(requestRow($run['report'], 'me.first')[1])->toBe('200')
        ->and(requestRow($run['report'], 'me.second')[1])->toBe('200');
});

it('sends the values of the fixture: a Bearer key, and an id that stays an integer', function (): void {
    $run = measureRequests(['--only' => 'notes.keyed']);

    expect(requestRow($run['report'], 'notes.keyed')[1])->toBe('200');
});

it('fails the row of a placeholder that the fixture did not make', function (): void {
    config()->set('page-performance.requests', [
        'notes.unknown' => ['method' => 'POST', 'path' => '/keyed', 'headers' => ['Authorization' => 'Bearer {fixture.missing}']],
    ]);

    $run = measureRequests();

    expect($run['exit'])->toBe(2)
        ->and(requestRow($run['report'], 'notes.unknown')[12])->toContain('COULD NOT MEASURE')
        ->toContain('{fixture.missing}');
});

it('refuses an outbound call: the row fails, and the call never leaves', function (): void {
    config()->set('page-performance.requests', ['calls.out' => ['method' => 'POST', 'path' => '/calls-out']]);

    $run = measureRequests();

    expect(requestRow($run['report'], 'calls.out')[12])->toContain('outbound call')
        ->toContain('api.example.test')
        ->and(SeenInRequests::$seen)->toContain(StrayRequestException::class)
        ->and(Http::preventingStrayRequests())->toBeFalse();
});

it('sends mail to an array mailer and jobs to a null queue, and puts both back', function (): void {
    config()->set('mail.default', 'log');
    config()->set('queue.default', 'sync');
    config()->set('page-performance.requests', ['mails.send' => ['method' => 'POST', 'path' => '/mails']]);

    $run = measureRequests();

    expect(requestRow($run['report'], 'mails.send')[1])->toBe('204')
        ->and(SeenInRequests::$seen)->toContain('page-performance-array')
        ->toContain('page-performance-null')
        ->and(config('mail.default'))->toBe('log')
        ->and(config('queue.default'))->toBe('sync');
});

it('judges the budget of a request by its name', function (): void {
    config()->set('page-performance.requests', ['many.read' => ['path' => '/many']]);
    config()->set('page-performance.budgets', ['many.read' => ['queries' => 1, 'duplicates' => 0, 'bytes' => null, 'components' => 0, 'measured' => '2026-11-17', 'why' => 'A test budget.']]);

    $run = measureRequests();

    expect(requestRow($run['report'], 'many.read')[12])->toContain('query-heavy')
        ->not->toContain('unbudgeted');
});

it('calls a request with no budget and no reason unbudgeted, and is quiet when a reason is given', function (): void {
    config()->set('page-performance.unbudgeted', ['notes.keyed' => 'A test reason.']);

    $run = measureRequests();

    expect(requestRow($run['report'], 'notes.store')[12])->toContain('unbudgeted')
        ->and(requestRow($run['report'], 'notes.keyed')[12])->not->toContain('unbudgeted');
});

it('refuses a request without a path', function (): void {
    config()->set('page-performance.requests', ['broken' => ['method' => 'POST']]);

    $this->artisan('perf:requests')
        ->expectsOutputToContain('Request "broken" has no path.')
        ->assertExitCode(2);
});

it('refuses a fixture class that is not a RequestFixture', function (): void {
    config()->set('page-performance.fixture', stdClass::class);

    $this->artisan('perf:requests')
        ->expectsOutputToContain('not a class that implements')
        ->assertExitCode(2);
});

it('says that no named request is not a pass', function (): void {
    config()->set('page-performance.requests', []);

    $this->artisan('perf:requests')
        ->expectsOutputToContain('NOT a pass')
        ->assertExitCode(2);
});

it('refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('perf:requests')
        ->expectsOutputToContain('will not do that against production')
        ->assertFailed();
});

it('leaves perf:pages as it was: a page table, no budget verdict', function (): void {
    Route::get('/plain-page', fn (): string => 'ok')->name('t.plain-page');
    config()->set('page-performance.include', ['t.plain-page']);

    Artisan::call('perf:pages', ['--json' => true, '--repeat' => 1]);

    /** @var array{columns: list<string>, rows: list<array<int, string>>, conditions: array<string, string>} $report */
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['columns'][0])->toBe('page')
        ->and($report['rows'][0][12])->toBe('ok')
        ->and($report['conditions']['Budgets'])->toStartWith('NOT evaluated');
});
