<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionRun;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Web\Crawler;
use Sifrious\Aleph\Web\CrawlLimits;
use Sifrious\Aleph\Web\CrawlSummary;
use Sifrious\Aleph\Web\FrontierFactory;
use Sifrious\Aleph\Web\UnknownWebSource;
use Sifrious\Aleph\Web\WebSource;
use Sifrious\Aleph\Web\WebSources;
use Throwable;

final class CrawlCommand extends Command
{
    protected $signature = 'aleph:crawl
        {source : The configured web source to crawl}
        {--max-pages= : Override the configured page limit}
        {--max-depth= : Override the configured depth limit}
        {--host=* : Restrict this crawl to the given hosts}
        {--fresh : Start a new run instead of resuming the latest unfinished one}';

    protected $description = 'Run a bounded crawl of a configured web source.';

    public function handle(
        WebSources $sources,
        IngestionRuns $runs,
        FrontierFactory $frontiers,
        Container $container,
    ): int {
        try {
            $source = $this->resolveSource($sources);
            $crawler = $container->make(Crawler::class);
            [$run, $source] = $this->resolveRun($runs, $source);
        } catch (UnknownWebSource|InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (BindingResolutionException $e) {
            $this->components->error("No web fetcher is bound: {$e->getMessage()}");

            return self::FAILURE;
        }

        $frontier = $frontiers->for($source, $run);

        $this->components->info(sprintf(
            'Crawling [%s] as run %s (max %d pages, max depth %d).',
            $source->name,
            $run->id,
            $source->limits->maxPages,
            $source->limits->maxDepth,
        ));

        try {
            $summary = $crawler->crawl($source, $frontier, $run);
        } catch (Throwable $e) {
            $runs->interrupt($run, $e->getMessage());
            $this->components->error("Crawl aborted: {$e->getMessage()}");

            return self::FAILURE;
        }

        $runs->complete($run, $summary->toArray());
        $this->report($summary);

        return self::SUCCESS;
    }

    private function resolveSource(WebSources $sources): WebSource
    {
        $name = $this->argument('source');

        if ($name === '') {
            throw new InvalidArgumentException('A web source name is required.');
        }

        return $sources->get($name);
    }

    private function limits(CrawlLimits $configured): CrawlLimits
    {
        return $configured->with(
            $this->intOption('max-pages'),
            $this->intOption('max-depth'),
        );
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw new InvalidArgumentException("Option [--{$name}] must be a non-negative integer.");
        }

        return (int) $value;
    }

    /**
     * @return array{IngestionRun, WebSource}
     */
    private function resolveRun(IngestionRuns $runs, WebSource $source): array
    {
        if (! $this->option('fresh')) {
            $existing = $runs->latestIncomplete($source->key, Capability::WebCrawl);

            if ($existing !== null) {
                $this->components->info("Resuming unfinished run {$existing->id}.");

                return [$runs->resume($existing), $this->sourceFor($source, $existing)];
            }
        }

        $source = $source
            ->withLimits($this->limits($source->limits))
            ->restrictedToHosts($this->hostRestrictions());

        return [$this->startRun($runs, $source), $source];
    }

    private function startRun(IngestionRuns $runs, WebSource $source): IngestionRun
    {
        return $runs->start(
            $source->key,
            Capability::WebCrawl,
            [
                'limits' => $source->limits->toArray(),
                'hosts' => $source->hosts->restrictions(),
            ],
        );
    }

    private function sourceFor(WebSource $source, IngestionRun $run): WebSource
    {
        $limits = $run->parameters['limits'] ?? null;
        $hosts = $run->parameters['hosts'] ?? null;

        if (! is_array($limits) || ! is_int($limits['max_pages'] ?? null) || ! is_int($limits['max_depth'] ?? null)) {
            throw new InvalidArgumentException("Ingestion run [{$run->id}] has invalid crawl limits.");
        }

        if (! is_array($hosts) || array_filter($hosts, fn (mixed $host): bool => ! is_string($host)) !== []) {
            throw new InvalidArgumentException("Ingestion run [{$run->id}] has invalid host restrictions.");
        }

        return $source
            ->withLimits(new CrawlLimits($limits['max_pages'], $limits['max_depth']))
            ->restrictedToHosts(array_values($hosts));
    }

    /**
     * @return list<string>
     */
    private function hostRestrictions(): array
    {
        return array_values(array_filter(
            array_map(strval(...), (array) $this->option('host')),
            fn (string $host): bool => $host !== '',
        ));
    }

    private function report(CrawlSummary $summary): void
    {
        $rows = [
            ['Run', $summary->runId],
            ['Source', $summary->source],
            ['Stopped by', $summary->stoppedBy->value],
            ['Fetched', (string) $summary->fetched],
            ['  not 2xx', (string) $summary->unsuccessful],
            ['Failed', (string) $summary->failed],
            ['Skipped', (string) $summary->skipped()],
        ];

        foreach ($summary->skippedByReason as $reason => $count) {
            $rows[] = ["  {$reason}", (string) $count];
        }

        $rows[] = ['Duplicates', (string) $summary->duplicates];
        $rows[] = ['Unresolvable', (string) $summary->unresolvable];
        $rows[] = ['Discovered', (string) $summary->discovered];
        $rows[] = ['Remaining', (string) $summary->remaining];

        $this->table(['Metric', 'Value'], $rows);
    }
}
