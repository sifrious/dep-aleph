<?php

declare(strict_types=1);

namespace Sifrious\Aleph;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Sifrious\Aleph\Acceptance\AcceptanceClient;
use Sifrious\Aleph\Acceptance\Backfill;
use Sifrious\Aleph\Acceptance\Submissions;
use Sifrious\Aleph\Connector\ConnectorCatalogue;
use Sifrious\Aleph\Connector\ConnectorCredentials;
use Sifrious\Aleph\Connector\ConnectorDispatcher;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Conversation\AiConversationSources;
use Sifrious\Aleph\Connector\Conversation\IngestAiConversations;
use Sifrious\Aleph\Connector\Git\GitChangeDetector;
use Sifrious\Aleph\Connector\Git\GitRepositorySources;
use Sifrious\Aleph\Connector\Git\ImportGitHistory;
use Sifrious\Aleph\Connector\GitHub\ConsumeGitHubWebhook;
use Sifrious\Aleph\Connector\GitHub\GitHubActivitySources;
use Sifrious\Aleph\Connector\GitHub\GitHubActivitySubmitter;
use Sifrious\Aleph\Connector\GitHub\GitHubWebhookDeliveries;
use Sifrious\Aleph\Connector\GitHub\GitHubWebhookNormalizer;
use Sifrious\Aleph\Connector\GitHub\GitHubWebhookSecrets;
use Sifrious\Aleph\Connector\GitHub\GitHubWebhookVerifier;
use Sifrious\Aleph\Connector\GitHub\ImportGitHubActivities;
use Sifrious\Aleph\Connector\Health\ConnectorHealthChecks;
use Sifrious\Aleph\Connector\Health\ConnectorHealthQueries;
use Sifrious\Aleph\Connector\RegisterSourceAccount;
use Sifrious\Aleph\Connector\Shell\IngestShellHistory;
use Sifrious\Aleph\Connector\Shell\ShellCommandTokenizer;
use Sifrious\Aleph\Connector\Shell\ShellHistorySources;
use Sifrious\Aleph\Connector\Shell\ShellRedactionPolicy;
use Sifrious\Aleph\Connector\Watch\RepositoryWatches;
use Sifrious\Aleph\Console\CrawlCommand;
use Sifrious\Aleph\Console\InventoryCommand;
use Sifrious\Aleph\Envelope\EnvelopeDrafter;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Ingestion\ContinuationLeases;
use Sifrious\Aleph\Ingestion\DomainRunQueries;
use Sifrious\Aleph\Ingestion\GitHubRunQueries;
use Sifrious\Aleph\Ingestion\IncrementalChanges;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionPartitions;
use Sifrious\Aleph\Ingestion\IngestionRunQueries;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\IngestionSchedules;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Ingestion\SourceStreamStatuses;
use Sifrious\Aleph\Inventory\InventoryReader;
use Sifrious\Aleph\Normalization\CandidateValidator;
use Sifrious\Aleph\Normalization\NormalizationAttempts;
use Sifrious\Aleph\Normalization\NormalizationCache;
use Sifrious\Aleph\Normalization\NormalizationRunner;
use Sifrious\Aleph\Scope\SourceScopeAssociations;
use Sifrious\Aleph\Web\Clock;
use Sifrious\Aleph\Web\Fetcher;
use Sifrious\Aleph\Web\FetchPolicy;
use Sifrious\Aleph\Web\FrontierFactory;
use Sifrious\Aleph\Web\HostThrottle;
use Sifrious\Aleph\Web\HttpFetcher;
use Sifrious\Aleph\Web\RobotsPolicy;
use Sifrious\Aleph\Web\SystemClock;
use Sifrious\Aleph\Web\WebSources;
use Sifrious\Funes\Acceptance\AcceptanceBacklog;
use Sifrious\Funes\Acceptance\AcceptanceGateway;
use Sifrious\Funes\Persistence\ObservationStore;

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
            IngestionRunQueries::class,
            fn (Application $app): IngestionRunQueries => new IngestionRunQueries($app->make(IngestionRuns::class)),
        );

        $this->app->singleton(
            GitHubRunQueries::class,
            fn (Application $app): GitHubRunQueries => new GitHubRunQueries($app->make(IngestionRunQueries::class)),
        );

        $this->app->singleton(
            IngestionSchedules::class,
            fn (Application $app): IngestionSchedules => new IngestionSchedules(
                $this->connection($app),
                $app->make(ConnectorInstallations::class),
                $app->make(ConnectorRegistry::class),
            ),
        );

        $this->app->singleton(
            ContinuationLeases::class,
            fn (Application $app): ContinuationLeases => new ContinuationLeases($this->connection($app)),
        );

        $this->app->singleton(
            SourceStreams::class,
            fn (Application $app): SourceStreams => new SourceStreams($this->connection($app)),
        );

        $this->app->singleton(
            SourceStreamStatuses::class,
            fn (Application $app): SourceStreamStatuses => new SourceStreamStatuses(
                $this->connection($app),
                $app->make(SourceStreams::class),
                $app->make(IngestionRuns::class),
                $app->make(IngestionCheckpoints::class),
            ),
        );

        $this->app->singleton(
            IngestionCheckpoints::class,
            fn (Application $app): IngestionCheckpoints => new IngestionCheckpoints(
                $this->connection($app),
                $app->make(IngestionRuns::class),
                $app->make(Submissions::class),
            ),
        );

        $this->app->singleton(
            IngestionPartitions::class,
            fn (Application $app): IngestionPartitions => new IngestionPartitions($this->connection($app)),
        );

        $this->app->singleton(
            IncrementalChanges::class,
            fn (Application $app): IncrementalChanges => new IncrementalChanges(
                $this->connection($app),
                $app->make(IngestionRuns::class),
                $app->make(Submissions::class),
            ),
        );

        $this->app->singleton(
            DomainRunQueries::class,
            fn (Application $app): DomainRunQueries => new DomainRunQueries($app->make(IngestionRunQueries::class)),
        );

        $this->app->singleton(
            FrontierFactory::class,
            fn (Application $app): FrontierFactory => new FrontierFactory($this->connection($app)),
        );

        $this->app->singleton(
            InventoryReader::class,
            fn (Application $app): InventoryReader => new InventoryReader(
                $this->connection($app),
                $app->make(ObservationStore::class),
            ),
        );

        $this->app->singleton(FetchPolicy::class, fn (Application $app): FetchPolicy => FetchPolicy::fromArray(
            (array) $app->make(Config::class)->get('aleph.http', []),
        ));

        $this->app->singleton(ConnectorRegistry::class);
        $this->app->singleton(AiConversationSources::class);

        $this->app->singleton(
            IngestAiConversations::class,
            fn (Application $app): IngestAiConversations => new IngestAiConversations(
                $app->make(ConnectorInstallations::class),
                $app->make(EnvelopeSubmitter::class),
            ),
        );

        $this->app->singleton(ShellHistorySources::class);
        $this->app->singleton(ShellCommandTokenizer::class);
        $this->app->singleton(ShellRedactionPolicy::class);

        $this->app->singleton(
            IngestShellHistory::class,
            fn (Application $app): IngestShellHistory => new IngestShellHistory(
                $app->make(ConnectorInstallations::class),
                $app->make(EnvelopeSubmitter::class),
                $app->make(ShellRedactionPolicy::class),
                $app->make(ShellCommandTokenizer::class),
            ),
        );

        $this->app->singleton(
            RepositoryWatches::class,
            fn (Application $app): RepositoryWatches => new RepositoryWatches(
                $this->connection($app),
                $app->make(ConnectorInstallations::class),
            ),
        );

        $this->app->singleton(GitRepositorySources::class);

        $this->app->singleton(GitChangeDetector::class);

        $this->app->singleton(GitHubActivitySources::class);
        $this->app->singleton(GitHubWebhookSecrets::class);
        $this->app->singleton(GitHubWebhookVerifier::class);
        $this->app->singleton(GitHubWebhookNormalizer::class);
        $this->app->singleton(GitHubActivitySubmitter::class);

        $this->app->singleton(
            GitHubWebhookDeliveries::class,
            fn (Application $app): GitHubWebhookDeliveries => new GitHubWebhookDeliveries(
                $this->connection($app),
                $app->make(Encrypter::class),
            ),
        );

        $this->app->singleton(
            ImportGitHubActivities::class,
            fn (Application $app): ImportGitHubActivities => new ImportGitHubActivities(
                $app->make(GitHubActivitySources::class),
                $app->make(ConnectorInstallations::class),
                $app->make(SourceStreams::class),
                $app->make(IngestionRuns::class),
                $app->make(IngestionCheckpoints::class),
                $app->make(GitHubActivitySubmitter::class),
            ),
        );

        $this->app->singleton(
            ConsumeGitHubWebhook::class,
            fn (Application $app): ConsumeGitHubWebhook => new ConsumeGitHubWebhook(
                $app->make(GitHubWebhookSecrets::class),
                $app->make(GitHubWebhookVerifier::class),
                $app->make(GitHubWebhookDeliveries::class),
                $app->make(GitHubWebhookNormalizer::class),
                $app->make(ConnectorInstallations::class),
                $app->make(GitHubActivitySubmitter::class),
            ),
        );

        $this->app->singleton(
            ImportGitHistory::class,
            fn (Application $app): ImportGitHistory => new ImportGitHistory(
                $app->make(GitRepositorySources::class),
                $app->make(SourceStreams::class),
                $app->make(IngestionRuns::class),
                $app->make(IngestionCheckpoints::class),
                $app->make(EnvelopeSubmitter::class),
                $app->make(GitChangeDetector::class),
            ),
        );

        $this->app->singleton(
            ConnectorDispatcher::class,
            fn (Application $app): ConnectorDispatcher => new ConnectorDispatcher($app->make(ConnectorRegistry::class)),
        );

        $this->app->singleton(
            ConnectorCatalogue::class,
            fn (Application $app): ConnectorCatalogue => new ConnectorCatalogue(
                $app->make(ConnectorRegistry::class),
                array_values(array_map(
                    strval(...),
                    (array) $app->make(Config::class)->get('aleph.connectors.disabled', []),
                )),
            ),
        );

        $this->app->singleton(
            ConnectorInstallations::class,
            fn (Application $app): ConnectorInstallations => new ConnectorInstallations(
                $this->connection($app),
                $app->make(Encrypter::class),
            ),
        );

        $this->app->singleton(
            ConnectorCredentials::class,
            fn (Application $app): ConnectorCredentials => new ConnectorCredentials(
                $this->connection($app),
                $app->make(Encrypter::class),
                $app->make(ConnectorInstallations::class),
            ),
        );

        $this->app->singleton(
            RegisterSourceAccount::class,
            fn (Application $app): RegisterSourceAccount => new RegisterSourceAccount(
                $this->connection($app),
                $app->make(ConnectorInstallations::class),
                $app->make(ConnectorCredentials::class),
            ),
        );

        $this->app->singleton(
            ConnectorHealthChecks::class,
            fn (Application $app): ConnectorHealthChecks => new ConnectorHealthChecks(
                $this->connection($app),
                $app->make(ConnectorInstallations::class),
            ),
        );

        $this->app->singleton(
            ConnectorHealthQueries::class,
            fn (Application $app): ConnectorHealthQueries => new ConnectorHealthQueries(
                $app->make(ConnectorInstallations::class),
                $app->make(ConnectorHealthChecks::class),
            ),
        );

        $this->app->singleton(
            SourceScopeAssociations::class,
            fn (Application $app): SourceScopeAssociations => new SourceScopeAssociations($this->connection($app)),
        );

        $this->app->singleton(
            EnvelopeDrafter::class,
            fn (Application $app): EnvelopeDrafter => new EnvelopeDrafter(
                $app->make(SourceScopeAssociations::class),
            ),
        );

        $this->app->singleton(
            EnvelopeSubmitter::class,
            fn (Application $app): EnvelopeSubmitter => new EnvelopeSubmitter(
                $app->make(AcceptanceClient::class),
                $app->make(EnvelopeDrafter::class),
            ),
        );

        $this->app->singleton(
            NormalizationAttempts::class,
            fn (Application $app): NormalizationAttempts => new NormalizationAttempts($this->connection($app)),
        );

        $this->app->singleton(CandidateValidator::class);

        $this->app->singleton(
            NormalizationCache::class,
            fn (Application $app): NormalizationCache => new NormalizationCache(
                $app->make(CacheRepository::class),
                (int) $app->make(Config::class)->get('aleph.normalization.cache_ttl', 604800),
            ),
        );

        $this->app->singleton(
            NormalizationRunner::class,
            fn (Application $app): NormalizationRunner => new NormalizationRunner(
                $app->make(NormalizationAttempts::class),
                $app->make(CandidateValidator::class),
                $app->make(Config::class)->get('aleph.normalization.cache_enabled', true)
                    ? $app->make(NormalizationCache::class)
                    : null,
            ),
        );

        $this->app->singleton(
            Submissions::class,
            fn (Application $app): Submissions => new Submissions($this->connection($app)),
        );

        $this->app->singleton(
            AcceptanceClient::class,
            fn (Application $app): AcceptanceClient => new AcceptanceClient(
                $app->make(AcceptanceGateway::class),
                $app->make(Submissions::class),
                $app->make(EnvelopeDrafter::class),
            ),
        );

        $this->app->singleton(
            Backfill::class,
            fn (Application $app): Backfill => new Backfill(
                $app->make(AcceptanceBacklog::class),
                $app->make(AcceptanceClient::class),
            ),
        );

        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(HostThrottle::class);
        $this->app->singleton(RobotsPolicy::class);

        $this->app->bind(Fetcher::class, HttpFetcher::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([CrawlCommand::class, InventoryCommand::class]);

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
