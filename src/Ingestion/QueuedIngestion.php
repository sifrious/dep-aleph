<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class QueuedIngestion
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public IngestionRun $run,
        public IngestionAttempt $attempt,
        public QueueDispatchPolicy $policy,
        public array $tags,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->run->id,
            'attempt_id' => $this->attempt->id,
            'attempt_number' => $this->attempt->number,
            'connector' => $this->run->connectorId,
            'source_installation' => $this->run->sourceInstallationId,
            'source' => $this->run->sourceReference,
            'capability' => $this->run->capability->value,
            'parameters' => $this->run->parameters,
            'queue' => $this->policy->queue->value,
            'priority' => $this->policy->priority,
            'tags' => $this->tags,
            'policy' => $this->policy->toArray(),
        ];
    }
}
