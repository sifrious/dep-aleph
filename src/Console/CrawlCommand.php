<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Sifrious\Aleph\Web\Crawler;
use Sifrious\Aleph\Web\CrawlLimits;
use Sifrious\Aleph\Web\CrawlRun;
use Sifrious\Aleph\Web\CrawlRuns;
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
        CrawlRuns $runs,
        FrontierFactory $frontiers,
        Container $container,
    ): int {
        try {
            $source = $this->resolveSource($sources);
            $crawler = $container->make(Crawler::class);
        } catch (UnknownWebSource|InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (BindingResolutionException $e) {
            $this->components->error("No web fetcher is bound: {$e->getMessage()}");

            return self::FAILURE;
        }

        $run = $this->resolveRun($runs, $source);
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
            $runs->abort($run, ['error' => $e->getMessage()]);
            $this->components->error("Crawl aborted: {$e->getMessage()}");

            return self::FAILURE;
        }

        $runs->complete($run, $summary);
        $this->report($summary);

        return self::SUCCESS;
    }

    private function resolveSource(WebSources $sources): WebSource
    {
        $name = $this->argument('source');

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('A web source name is required.');
        }

        $source = $sources->get($name);

        $source = $source->withLimits($this->limits($source->limits));

        $hosts = array_values(array_filter(
            array_map(strval(...), (array) $this->option('host')),
            fn (string $host): bool => $host !== '',
        ));

        return $hosts === [] ? $source : $source->restrictedToHosts($hosts);
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

    private function resolveRun(CrawlRuns $runs, WebSource $source): CrawlRun
    {
        if ($this->option('fresh')) {
            return $runs->start($source);
        }

        $existing = $runs->latestRunning($source->key);

        if ($existing !== null) {
            $this->components->info("Resuming unfinished run {$existing->id}.");

            return $existing;
        }

        return $runs->start($source);
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
