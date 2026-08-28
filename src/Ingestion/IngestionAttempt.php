<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class IngestionAttempt
{
    /**
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, int|float>  $stats
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $id,
        public string $runId,
        public int $number,
        public RunStatus $status,
        public array $checkpoint,
        public array $stats,
        public ?RunFailure $failure,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?QueueClass $queue = null,
        public ?int $priority = null,
        public array $tags = [],
        public ?QueueDispatchPolicy $dispatchPolicy = null,
        public ?string $queueJobId = null,
        public ?string $workerId = null,
        public ?DateTimeImmutable $queuedAt = null,
        public ?DateTimeImmutable $heartbeatAt = null,
        public ?string $retryOfId = null,
        public ?string $retryReason = null,
        public ?string $partitionKey = null,
        public ?bool $retryable = null,
        public ?DateTimeImmutable $backoffUntil = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'retry_of_id' => $this->retryOfId,
            'retry_reason' => $this->retryReason,
            'partition_key' => $this->partitionKey,
            'retryable' => $this->retryable,
            'backoff_until' => $this->backoffUntil?->format(DATE_ATOM),
            'queue' => $this->queue?->value,
            'priority' => $this->priority,
            'tags' => $this->tags,
            'dispatch_policy' => $this->dispatchPolicy?->toArray(),
            'queue_job_id' => $this->queueJobId,
            'worker_id' => $this->workerId,
            'status' => $this->status->value,
            'checkpoint' => $this->checkpoint,
            'stats' => $this->stats,
            'failure' => $this->failure?->toArray(),
            'queued_at' => $this->queuedAt?->format(DATE_ATOM),
            'started_at' => $this->startedAt?->format(DATE_ATOM),
            'heartbeat_at' => $this->heartbeatAt?->format(DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(DATE_ATOM),
        ];
    }
}
