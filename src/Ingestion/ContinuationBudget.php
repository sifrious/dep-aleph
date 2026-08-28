<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class ContinuationBudget
{
    public function __construct(
        public int $maxPartitions,
        public int $maxRecords,
        public int $maxRuntimeSeconds,
    ) {
        if ($maxPartitions < 1 || $maxRecords < 1 || $maxRuntimeSeconds < 1) {
            throw new InvalidArgumentException('Continuation budgets must be positive.');
        }
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'max_partitions' => $this->maxPartitions,
            'max_records' => $this->maxRecords,
            'max_runtime_seconds' => $this->maxRuntimeSeconds,
        ];
    }
}
