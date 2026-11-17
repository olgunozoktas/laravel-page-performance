<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

use Illuminate\Support\Facades\DB;

/**
 * The lines of the conditions block that differ for `perf:requests`.
 *
 * A report must say what it measured. A page sweep reads the real data, judges
 * no budget and writes nothing; a named request runs on the host's fixture,
 * judges its budget and makes writes that roll back. Printing the page lines
 * over a request run would state three things that are not true.
 */
final readonly class RequestConditions
{
    /**
     * @param  array<string, string>  $conditions  the lines of a page sweep
     * @return array<string, string>
     */
    public static function apply(array $conditions): array
    {
        $conditions['Database'] = sprintf(
            '%s "%s" — the local data and the rows of %s, in a transaction that is rolled back',
            DB::getDefaultConnection(),
            DB::connection()->getDatabaseName(),
            self::fixture(),
        );
        $conditions['Coverage'] = str_replace('not requestable', 'not named', $conditions['Coverage'] ?? '');
        $conditions['Isolation'] = 'each run rolled back · outbound HTTP refused · mail to an array mailer · queued jobs dropped · a cache write, a file write and a second connection are NOT rolled back';
        $conditions['Budgets'] = 'evaluated — page-performance.budgets by request name; a request with no budget and no unbudgeted reason is a Defect';
        $conditions['Not measured'] = 'network, TLS, opcache-warm production, concurrency, the work of a queued job';

        return $conditions;
    }

    private static function fixture(): string
    {
        $fixture = config('page-performance.fixture');

        return is_string($fixture) && $fixture !== '' ? $fixture : 'no fixture';
    }
}
