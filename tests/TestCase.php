<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sifrious\Aleph\AlephServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AlephServiceProvider::class];
    }
}
