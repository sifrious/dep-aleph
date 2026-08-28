<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class CompleteBackfill
{
    public function __construct(
        private IngestionRuns $runs,
        private IngestionPartitions $partitions,
    ) {}

    public function complete(IngestionRun $run, IngestionAttempt $attempt): IngestionRun
    {
        if (! $this->partitions->allComplete($run)) {
            throw new InvalidArgumentException('A backfill cannot complete while partitions remain incomplete.');
        }

        $currentRun = $this->runs->find($run->id);
        $currentAttempt = $this->runs->attempt($attempt->id);

        if ($currentRun === null || $currentAttempt === null) {
            throw new InvalidArgumentException('The backfill run or attempt no longer exists.');
        }

        $partitions = $this->partitions->forRun($currentRun);
        $stats = [
            'partitions' => count($partitions),
            'processed' => array_sum(array_map(static fn (IngestionPartition $partition): int => $partition->processed, $partitions)),
            'accepted' => array_sum(array_map(static fn (IngestionPartition $partition): int => $partition->accepted, $partitions)),
            'failed' => array_sum(array_map(static fn (IngestionPartition $partition): int => $partition->failed, $partitions)),
        ];
        $this->runs->succeedAttempt($currentRun, $currentAttempt, $stats, $currentRun->acceptedReferences);

        return $this->runs->find($currentRun->id) ?? $currentRun;
    }
}
