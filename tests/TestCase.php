<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sifrious\Aleph\AlephServiceProvider;
use Sifrious\Funes\FunesServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FunesServiceProvider::class, AlephServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
