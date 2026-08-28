<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class IngestionRun
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, int|float>  $stats
     * @param  list<string>  $acceptedReferences
     * @param  list<array<string, mixed>>  $remainingWork
     */
    public function __construct(
        public string $id,
        public string $sourceReference,
        public Capability $capability,
        public RunStatus $status,
        public array $parameters,
        public ?DateTimeImmutable $startedAt,
        public ?string $connectorId = null,
        public ?string $sourceInstallationId = null,
        public ?string $legacyReference = null,
        public ?string $idempotencyKey = null,
        public RunCompleteness $completeness = RunCompleteness::Incomplete,
        public array $checkpoint = [],
        public array $stats = [],
        public ?RunFailure $failure = null,
        public array $acceptedReferences = [],
        public ?DateTimeImmutable $finishedAt = null,
        public IngestionTrigger $trigger = IngestionTrigger::System,
        public ?string $requestedBy = null,
        public ?string $authorizationDecision = null,
        public ?DateTimeImmutable $requestedAt = null,
        public array $remainingWork = [],
        public int $warningCount = 0,
        public int $errorCount = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'connector' => $this->connectorId,
            'source_installation' => $this->sourceInstallationId,
            'source' => $this->sourceReference,
            'capability' => $this->capability->value,
            'trigger' => $this->trigger->value,
            'requested_by' => $this->requestedBy,
            'authorization_decision' => $this->authorizationDecision,
            'status' => $this->status->value,
            'completeness' => $this->completeness->value,
            'parameters' => $this->parameters,
            'checkpoint' => $this->checkpoint,
            'remaining_work' => $this->remainingWork,
            'stats' => $this->stats,
            'warning_count' => $this->warningCount,
            'error_count' => $this->errorCount,
            'failure' => $this->failure?->toArray(),
            'accepted_references' => $this->acceptedReferences,
            'legacy_reference' => $this->legacyReference,
            'requested_at' => $this->requestedAt?->format(DATE_ATOM),
            'started_at' => $this->startedAt?->format(DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(DATE_ATOM),
        ];
    }
}
