<?php

declare(strict_types=1);

namespace Olgun\PagePerformance;

/**
 * The host's hook for `perf:requests`: it makes the rows that a request needs.
 *
 * The package cannot know how an application makes a user, a team or an API
 * key. The host names one class in `page-performance.fixture`, and the package
 * resolves it from the container.
 *
 * `setUp()` RUNS IN A TRANSACTION THAT IS ROLLED BACK. Each row it makes is
 * gone when the command ends. It returns the values that a request names as
 * `{fixture.key}` in its path, a header value or its JSON body, for example a
 * plain API key for an `Authorization: Bearer {fixture.key}` header.
 *
 * A TRANSACTION DOES NOT UNDO A CACHE WRITE OR A FILE WRITE. Set the cache to
 * the `array` store in `setUp()` when a request writes to the cache, or lift a
 * rate limiter there, and put it back in `tearDown()`.
 */
interface RequestFixture
{
    /**
     * @return array<string, string|int|float|bool>
     */
    public function setUp(): array;

    public function tearDown(): void;
}
