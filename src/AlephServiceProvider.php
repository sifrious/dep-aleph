<?php

declare(strict_types=1);

namespace Sifrious\Aleph;

use Illuminate\Support\ServiceProvider;

class AlephServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/aleph.php', 'aleph');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/aleph.php' => $this->app->configPath('aleph.php'),
            ], 'aleph-config');
        }
    }
}
