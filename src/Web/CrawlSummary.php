<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

final readonly class CrawlSummary
{
    /**
     * @param  array<string, int>  $skippedByReason
     */
    public function __construct(
        public string $runId,
        public string $source,
        public int $fetched,
        public int $unsuccessful,
        public int $failed,
        public array $skippedByReason,
        public int $duplicates,
        public int $unresolvable,
        public int $discovered,
        public int $remaining,
        public StopReason $stoppedBy,
    ) {}

    public function skipped(): int
    {
        return array_sum($this->skippedByReason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fetched' => $this->fetched,
            'unsuccessful' => $this->unsuccessful,
            'failed' => $this->failed,
            'skipped' => $this->skipped(),
            'skipped_by_reason' => $this->skippedByReason,
            'duplicates' => $this->duplicates,
            'unresolvable' => $this->unresolvable,
            'discovered' => $this->discovered,
            'remaining' => $this->remaining,
            'stopped_by' => $this->stoppedBy->value,
        ];
    }
}
