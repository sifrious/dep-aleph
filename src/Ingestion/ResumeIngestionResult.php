<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class ResumeIngestionResult
{
    /**
     * @param  list<IngestionContinuation>  $continuations
     * @param  list<ContinuationLease>  $leases
     */
    public function __construct(
        public IngestionRun $run,
        public IngestionAttempt $attempt,
        public array $continuations,
        public array $leases,
        public ContinuationBudget $budget,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run' => $this->run->toArray(),
            'attempt' => $this->attempt->toArray(),
            'continuations' => array_map(
                static fn (IngestionContinuation $continuation): array => $continuation->toArray(),
                $this->continuations,
            ),
            'lease_ids' => array_map(
                static fn (ContinuationLease $lease): string => $lease->id,
                $this->leases,
            ),
            'budget' => $this->budget->toArray(),
        ];
    }
}
