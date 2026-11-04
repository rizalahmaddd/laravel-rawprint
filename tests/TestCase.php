<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RawPrint\Laravel\RawPrintServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RawPrintServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('rawprint.base_url', 'http://127.0.0.1:18310');
        $app['config']->set('rawprint.token', 'secret-token');
        $app['config']->set('rawprint.origin', null);

        $app['view']->addLocation(__DIR__.'/views');
    }
}
