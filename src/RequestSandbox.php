<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use Closure;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * What keeps a measured write from staying: `perf:requests` sends POSTs.
 *
 * TWO TRANSACTIONS. The fixture runs in an outer transaction, and each run of
 * a request in an inner one (a save point). Both roll back, so a row that a
 * request or the fixture writes is gone when the command ends. Only the
 * DEFAULT connection is in the transaction: a write on a second connection
 * stays, and the report says so.
 *
 * NO CALL LEAVES THE PROCESS. `Http::preventStrayRequests()` is on for the
 * whole command: Laravel's client throws before it sends, and the measurer
 * fails the row with "outbound call". A raw curl handle or an SDK with its own
 * Guzzle is not seen, the same limit as `vendor-bound`.
 *
 * NO MAIL AND NO JOB LEAVES IT. Mail goes to an `array` mailer, and a queued
 * job to a `null` queue: a job pushed to Redis would run outside the
 * transaction.
 *
 * EVERY SETTING IS PUT BACK in a `finally`, in the order it was changed.
 */
final readonly class RequestSandbox
{
    private const string ARRAY_MAILER = 'page-performance-array';

    private const string NULL_QUEUE = 'page-performance-null';

    public function __construct(private ?RequestFixture $fixture = null) {}

    /**
     * The sandbox with the fixture that `page-performance.fixture` names.
     *
     * @throws InvalidArgumentException when the named class does not implement RequestFixture
     */
    public static function fromConfig(): self
    {
        $class = config('page-performance.fixture');

        if ($class === null || $class === '') {
            return new self;
        }

        $fixture = is_string($class) && class_exists($class) ? app($class) : null;

        if (! $fixture instanceof RequestFixture) {
            throw new InvalidArgumentException(sprintf(
                'page-performance.fixture names "%s", which is not a class that implements %s.',
                is_string($class) ? $class : get_debug_type($class),
                RequestFixture::class,
            ));
        }

        return new self($fixture);
    }

    public function hasFixture(): bool
    {
        return $this->fixture instanceof RequestFixture;
    }

    /**
     * Run the whole measurement inside the outer transaction.
     *
     * @template TResult
     *
     * @param  Closure(array<string, string|int|float|bool>): TResult  $measure  gets the values of `setUp()`
     * @return TResult
     */
    public function around(Closure $measure): mixed
    {
        $level = DB::transactionLevel();
        $mailer = config('mail.default');
        $queue = config('queue.default');
        $preventing = Http::preventingStrayRequests();

        config([
            'mail.mailers.'.self::ARRAY_MAILER => ['transport' => 'array'],
            'mail.default' => self::ARRAY_MAILER,
            'queue.connections.'.self::NULL_QUEUE => ['driver' => 'null'],
            'queue.default' => self::NULL_QUEUE,
        ]);
        $this->forgetMailers();
        Http::preventStrayRequests();
        DB::beginTransaction();

        try {
            $values = $this->fixture?->setUp() ?? [];

            try {
                return $measure($values);
            } finally {
                $this->fixture?->tearDown();
            }
        } finally {
            DB::rollBack($level);
            Http::preventStrayRequests($preventing);
            config(['mail.default' => $mailer, 'queue.default' => $queue]);
            $this->forgetMailers();
        }
    }

    /**
     * Run one request in an inner transaction that rolls back.
     *
     * The guards are forgotten first: a guard that read a Bearer key keeps its
     * user, and the next request with another key would be measured as the
     * first one's user.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $run
     * @return TResult
     */
    public function isolate(Closure $run): mixed
    {
        $level = DB::transactionLevel();

        Auth::forgetGuards();
        DB::beginTransaction();

        try {
            return $run();
        } finally {
            // To the level before the run, also when the request left a
            // transaction open: a commit after this would keep its rows.
            DB::rollBack($level);
        }
    }

    private function forgetMailers(): void
    {
        resolve(MailManager::class)->forgetMailers();
    }
}
