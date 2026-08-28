<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class IngestionRunReadModel
{
    /**
     * @param  list<IngestionAttempt>  $attempts
     */
    public function __construct(
        public IngestionRun $run,
        public array $attempts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->run->toArray(),
            'attempts' => array_map(
                static fn (IngestionAttempt $attempt): array => $attempt->toArray(),
                $this->attempts,
            ),
            'next_action' => $this->nextAction(),
        ];
    }

    private function nextAction(): string
    {
        if ($this->run->status === RunStatus::Pending) {
            return 'start';
        }

        if (in_array($this->run->status, [RunStatus::Interrupted, RunStatus::Partial], true)) {
            return 'resume';
        }

        if ($this->run->status === RunStatus::Failed && $this->run->failure?->retryable === true) {
            return 'retry';
        }

        return 'none';
    }
}
