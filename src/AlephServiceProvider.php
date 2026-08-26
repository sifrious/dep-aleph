<?php

declare(strict_types=1);

namespace Sifrious\Aleph;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Sifrious\Aleph\Console\CrawlCommand;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Web\Clock;
use Sifrious\Aleph\Web\Fetcher;
use Sifrious\Aleph\Web\FetchPolicy;
use Sifrious\Aleph\Web\FrontierFactory;
use Sifrious\Aleph\Web\HostThrottle;
use Sifrious\Aleph\Web\HttpFetcher;
use Sifrious\Aleph\Web\LinkSource;
use Sifrious\Aleph\Web\NoLinks;
use Sifrious\Aleph\Web\RobotsPolicy;
use Sifrious\Aleph\Web\SystemClock;
use Sifrious\Aleph\Web\WebSources;

class AlephServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/aleph.php', 'aleph');

        $this->app->bind(WebSources::class, fn (Application $app): WebSources => new WebSources(
            (array) $app->make(Config::class)->get('aleph.web_sources', []),
        ));

        $this->app->singleton(
            IngestionRuns::class,
            fn (Application $app): IngestionRuns => new IngestionRuns($this->connection($app)),
        );

        $this->app->singleton(
            FrontierFactory::class,
            fn (Application $app): FrontierFactory => new FrontierFactory($this->connection($app)),
        );

        $this->app->singleton(FetchPolicy::class, fn (Application $app): FetchPolicy => FetchPolicy::fromArray(
            (array) $app->make(Config::class)->get('aleph.http', []),
        ));

        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(HostThrottle::class);
        $this->app->singleton(RobotsPolicy::class);

        $this->app->bind(Fetcher::class, HttpFetcher::class);
        $this->app->bind(LinkSource::class, NoLinks::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([CrawlCommand::class]);

            $this->publishes([
                __DIR__.'/../config/aleph.php' => $this->app->configPath('aleph.php'),
            ], 'aleph-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'aleph-migrations');
        }
    }

    private function connection(Application $app): ConnectionInterface
    {
        $name = $app->make(Config::class)->get('aleph.connection');

        return $app->make(DatabaseManager::class)->connection(is_string($name) ? $name : null);
    }
}
