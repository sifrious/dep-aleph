<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use DateTimeImmutable;
use Sifrious\Aleph\Ingestion\ContinuationBudget;
use Sifrious\Aleph\Ingestion\ResumeIngestion;
use Sifrious\Aleph\Ingestion\ResumeIngestionRequest;
use Throwable;

final class ResumeRunCommand extends AlephCommand
{
    protected $signature = 'aleph:run:resume
        {run : Interrupted or partial ingestion run ID}
        {stream : Source stream ID}
        {--partition=* : Required partition key, repeatable}
        {--lease-owner= : Required stable worker or operator reference}
        {--lease-seconds=300 : Lease duration}
        {--max-partitions=10 : Continuation partition bound}
        {--max-records=1000 : Continuation record bound}
        {--max-runtime=300 : Continuation runtime bound in seconds}
        {--json : Emit JSON}';

    protected $description = 'Resume bounded work from accepted Aleph checkpoints.';

    public function handle(ResumeIngestion $resume): int
    {
        try {
            $owner = trim((string) $this->option('lease-owner'));

            if ($owner === '') {
                throw new \InvalidArgumentException('Resume requires a lease owner.');
            }

            $result = $resume->resume(new ResumeIngestionRequest(
                runId: (string) $this->argument('run'),
                sourceStreamId: (string) $this->argument('stream'),
                partitionKeys: array_values(array_map(strval(...), (array) $this->option('partition'))),
                leaseOwner: $owner,
                budget: new ContinuationBudget(
                    (int) $this->option('max-partitions'),
                    (int) $this->option('max-records'),
                    (int) $this->option('max-runtime'),
                ),
                leaseSeconds: (int) $this->option('lease-seconds'),
                requestedAt: new DateTimeImmutable,
            ));
        } catch (Throwable $failure) {
            return $this->failure($failure);
        }

        $data = $result->toArray();

        if ((bool) $this->option('json')) {
            return $this->json($data);
        }

        $this->table(['Field', 'Value'], [
            ['Run', $result->run->id],
            ['Attempt', $result->attempt->id],
            ['Continuations', (string) count($result->continuations)],
            ['Leases', (string) count($result->leases)],
        ]);

        return self::SUCCESS;
    }
}
