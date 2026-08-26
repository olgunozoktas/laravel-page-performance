<?php

declare(strict_types=1);

namespace Olgun\PagePerformance\Tests;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Olgun\PagePerformance\PagePerformanceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PagePerformanceServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $config->set('app.debug', true);
    }
}
