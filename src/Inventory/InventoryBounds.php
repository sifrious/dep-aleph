<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Inventory;

use DateTimeImmutable;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\RunStatus;

final readonly class InventoryBounds
{
    /**
     * @param  list<string>  $seeds
     * @param  list<string>  $allowedHosts
     * @param  list<string>  $hostRestrictions
     * @param  list<string>  $excluded
     * @param  list<string>  $queryParameters
     * @param  list<string>  $calendarSignals
     * @param  array<string, mixed>  $stats
     */
    public function __construct(
        public string $runId,
        public string $sourceReference,
        public string $sourceName,
        public Capability $capability,
        public RunStatus $status,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public int $maxPages,
        public int $maxDepth,
        public array $seeds,
        public array $allowedHosts,
        public array $hostRestrictions,
        public array $excluded,
        public array $queryParameters,
        public array $calendarSignals,
        public array $stats,
        public ?string $error,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'source_reference' => $this->sourceReference,
            'source_name' => $this->sourceName,
            'capability' => $this->capability->value,
            'status' => $this->status->value,
            'started_at' => InventoryResource::instant($this->startedAt),
            'finished_at' => InventoryResource::instant($this->finishedAt),
            'max_pages' => $this->maxPages,
            'max_depth' => $this->maxDepth,
            'seeds' => $this->seeds,
            'allowed_hosts' => $this->allowedHosts,
            'host_restrictions' => $this->hostRestrictions,
            'excluded' => $this->excluded,
            'query_parameters' => $this->queryParameters,
            'calendar_signals' => $this->calendarSignals,
            'stats' => $this->stats,
            'error' => $this->error,
        ];
    }
}
