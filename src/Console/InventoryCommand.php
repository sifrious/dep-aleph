<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionRun;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Inventory\CsvInventory;
use Sifrious\Aleph\Inventory\Inventory;
use Sifrious\Aleph\Inventory\InventoryReader;
use Sifrious\Aleph\Inventory\JsonInventory;
use Sifrious\Aleph\Web\CrawlParameters;
use Sifrious\Aleph\Web\UnknownWebSource;
use Sifrious\Aleph\Web\WebSource;
use Sifrious\Aleph\Web\WebSources;

final class InventoryCommand extends Command
{
    protected $signature = 'aleph:inventory
        {source : The configured web source to inventory}
        {--run= : Inventory this ingestion run instead of the latest one}
        {--json= : Write the JSON inventory to this path}
        {--csv= : Write the CSV inventory to this path}';

    protected $description = 'Export a deterministic inventory of one crawl run.';

    public function handle(
        WebSources $sources,
        IngestionRuns $runs,
        InventoryReader $reader,
        JsonInventory $json,
        CsvInventory $csv,
    ): int {
        try {
            $source = $sources->get((string) $this->argument('source'));
            $run = $this->resolveRun($runs, $source);
            $source = CrawlParameters::fromRun($run)->applyTo($source);
        } catch (UnknownWebSource|InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $inventory = $reader->read($source, $run);

        $this->write('--json', $json->encode($inventory));
        $this->write('--csv', $csv->encode($inventory));
        $this->report($inventory);

        return self::SUCCESS;
    }

    private function resolveRun(IngestionRuns $runs, WebSource $source): IngestionRun
    {
        $id = $this->option('run');

        if (is_string($id) && $id !== '') {
            return $this->ofSource($source, $id, $runs->find($id));
        }

        return $runs->latest($source->reference(), Capability::WebCrawl)
            ?? throw new InvalidArgumentException("Web source [{$source->key}] has no ingestion run to inventory.");
    }

    private function ofSource(WebSource $source, string $id, ?IngestionRun $run): IngestionRun
    {
        if ($run === null) {
            throw new InvalidArgumentException("Ingestion run [{$id}] does not exist.");
        }

        if ($run->sourceReference !== $source->reference()) {
            throw new InvalidArgumentException(
                "Ingestion run [{$id}] belongs to source [{$run->sourceReference}], not [{$source->reference()}].",
            );
        }

        return $run;
    }

    private function write(string $option, string $contents): void
    {
        $path = $this->option(ltrim($option, '-'));

        if (! is_string($path) || $path === '') {
            return;
        }

        file_put_contents($path, $contents);
        $this->components->info("Wrote {$path}.");
    }

    private function report(Inventory $inventory): void
    {
        $rows = [
            ['Run', $inventory->bounds->runId],
            ['Source', $inventory->bounds->sourceReference],
            ['Status', $inventory->bounds->status->value],
            ['Bounds', sprintf('max %d pages, max depth %d', $inventory->bounds->maxPages, $inventory->bounds->maxDepth)],
        ];

        foreach ($inventory->totals() as $metric => $value) {
            if (is_array($value)) {
                foreach ($value as $key => $count) {
                    $rows[] = ["  {$key}", (string) $count];
                }

                continue;
            }

            $rows[] = [ucfirst(str_replace('_', ' ', (string) $metric)), (string) $value];
        }

        $this->table(['Metric', 'Value'], $rows);
    }
}
