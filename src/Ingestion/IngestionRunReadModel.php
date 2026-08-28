<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class IngestionRunReadModel
{
    /**
     * @param  list<IngestionAttempt>  $attempts
     * @param  list<IngestionFailure>  $failures
     */
    public function __construct(
        public IngestionRun $run,
        public array $attempts,
        public array $failures = [],
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
            'failures' => array_map(
                static fn (IngestionFailure $failure): array => $failure->toArray(),
                $this->failures,
            ),
            'next_action' => $this->nextAction()->value,
        ];
    }

    private function nextAction(): RecoveryAction
    {
        if ($this->run->status === RunStatus::Pending) {
            return RecoveryAction::Start;
        }

        if (in_array($this->run->status, [RunStatus::Interrupted, RunStatus::Partial], true)) {
            return RecoveryAction::Resume;
        }

        if ($this->run->status === RunStatus::Failed && $this->run->failure !== null) {
            return $this->run->failure->recoveryAction();
        }

        if ($this->run->status === RunStatus::Canceled) {
            return RecoveryAction::Restart;
        }

        return RecoveryAction::None;
    }
}
