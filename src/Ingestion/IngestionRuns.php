<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

final readonly class IngestionRuns
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $checkpoint
     */
    public function start(
        string $sourceReference,
        Capability $capability,
        array $parameters,
        ?string $connectorId = null,
        ?string $sourceInstallationId = null,
        ?string $idempotencyKey = null,
        array $checkpoint = [],
    ): IngestionRun {
        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }
        }

        $run = new IngestionRun(
            id: (string) Str::ulid(),
            sourceReference: $sourceReference,
            capability: $capability,
            status: RunStatus::Running,
            parameters: $parameters,
            startedAt: new DateTimeImmutable,
            connectorId: $connectorId,
            sourceInstallationId: $sourceInstallationId,
            idempotencyKey: $idempotencyKey,
            checkpoint: $checkpoint,
        );

        $this->insert($run);

        return $run;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function request(
        string $sourceReference,
        Capability $capability,
        array $parameters,
        string $connectorId,
        string $sourceInstallationId,
        string $idempotencyKey,
        IngestionTrigger $trigger,
        string $requestedBy,
        string $authorizationDecision,
    ): IngestionRun {
        $existing = $this->findByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $run = new IngestionRun(
            id: (string) Str::ulid(),
            sourceReference: $sourceReference,
            capability: $capability,
            status: RunStatus::Pending,
            parameters: $parameters,
            startedAt: null,
            connectorId: $connectorId,
            sourceInstallationId: $sourceInstallationId,
            idempotencyKey: $idempotencyKey,
            trigger: $trigger,
            requestedBy: $requestedBy,
            authorizationDecision: $authorizationDecision,
            requestedAt: new DateTimeImmutable,
        );

        $this->insert($run);

        return $run;
    }

    public function import(LegacySyncRun $legacy): IngestionRun
    {
        $existing = $this->findByLegacyReference($legacy->legacyReference);

        if ($existing !== null) {
            return $existing;
        }

        $run = new IngestionRun(
            id: LegacyRunIdentity::for($legacy->legacyReference),
            sourceReference: $legacy->sourceReference,
            capability: $legacy->capability,
            status: $legacy->status,
            parameters: $legacy->parameters,
            startedAt: $legacy->startedAt,
            connectorId: $legacy->connectorId,
            sourceInstallationId: $legacy->sourceInstallationId,
            legacyReference: $legacy->legacyReference,
            completeness: $legacy->completeness,
            checkpoint: $legacy->checkpoint,
            stats: $legacy->stats,
            failure: $legacy->failure,
            acceptedReferences: $legacy->acceptedReferences,
            finishedAt: $legacy->finishedAt,
            errorCount: $legacy->failure === null ? 0 : 1,
        );

        $this->insert($run);

        return $run;
    }

    public function latestIncomplete(string $sourceReference, Capability $capability): ?IngestionRun
    {
        $row = $this->table()
            ->where('source_reference', $sourceReference)
            ->where('capability', $capability->value)
            ->whereIn('status', [
                RunStatus::Pending->value,
                RunStatus::Running->value,
                RunStatus::Partial->value,
                RunStatus::Interrupted->value,
            ])
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function find(string $id): ?IngestionRun
    {
        $row = $this->table()->where('id', $id)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function latest(string $sourceReference, Capability $capability): ?IngestionRun
    {
        $row = $this->table()
            ->where('source_reference', $sourceReference)
            ->where('capability', $capability->value)
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<IngestionRun>
     */
    public function recent(int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Run list limit must be between 1 and 100.');
        }

        return array_values($this->table()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (stdClass $row): IngestionRun => $this->hydrate($row))
            ->all());
    }

    /**
     * @return list<IngestionRun>
     */
    public function forInstallation(string $sourceInstallationId, int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Run list limit must be between 1 and 100.');
        }

        return array_values($this->table()
            ->where('source_installation_id', $sourceInstallationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (stdClass $row): IngestionRun => $this->hydrate($row))
            ->all());
    }

    public function hasActiveBackoff(
        string $sourceInstallationId,
        Capability $capability,
        DateTimeImmutable $now,
    ): bool {
        return $this->connection->table('aleph_ingestion_attempts as attempts')
            ->join('aleph_ingestion_runs as runs', 'runs.id', '=', 'attempts.run_id')
            ->where('runs.source_installation_id', $sourceInstallationId)
            ->where('runs.capability', $capability->value)
            ->where('attempts.backoff_until', '>', $now)
            ->exists();
    }

    public function findByIdempotencyKey(string $key): ?IngestionRun
    {
        $row = $this->table()->where('idempotency_key', $key)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByLegacyReference(string $reference): ?IngestionRun
    {
        $row = $this->table()->where('legacy_reference', $reference)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function resume(IngestionRun $run): IngestionRun
    {
        $this->assertTransition($run, RunStatus::Running);

        if ($run->status === RunStatus::Failed && $run->failure?->retryable !== true) {
            throw InvalidRunTransition::from($run->status, RunStatus::Running);
        }

        $startedAt = $run->startedAt ?? new DateTimeImmutable;

        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Running->value,
            'error' => null,
            'failure' => null,
            'started_at' => $startedAt,
            'finished_at' => null,
        ]);

        return new IngestionRun(
            id: $run->id,
            sourceReference: $run->sourceReference,
            capability: $run->capability,
            status: RunStatus::Running,
            parameters: $run->parameters,
            startedAt: $startedAt,
            connectorId: $run->connectorId,
            sourceInstallationId: $run->sourceInstallationId,
            legacyReference: $run->legacyReference,
            idempotencyKey: $run->idempotencyKey,
            completeness: RunCompleteness::Incomplete,
            checkpoint: $run->checkpoint,
            stats: $run->stats,
            acceptedReferences: $run->acceptedReferences,
            trigger: $run->trigger,
            requestedBy: $run->requestedBy,
            authorizationDecision: $run->authorizationDecision,
            requestedAt: $run->requestedAt,
            remainingWork: $run->remainingWork,
            warningCount: $run->warningCount,
            errorCount: $run->errorCount,
        );
    }

    public function beginAttempt(IngestionRun $run): IngestionAttempt
    {
        $active = $this->attemptsTable()
            ->where('run_id', $run->id)
            ->where('status', RunStatus::Running->value)
            ->first();

        if ($active !== null) {
            return $this->hydrateAttempt($active);
        }

        $current = $run->status === RunStatus::Running ? $run : $this->resume($run);
        $number = ((int) $this->attemptsTable()->where('run_id', $run->id)->max('number')) + 1;
        $attempt = new IngestionAttempt(
            id: (string) Str::ulid(),
            runId: $run->id,
            number: $number,
            status: RunStatus::Running,
            checkpoint: $current->checkpoint,
            stats: $current->stats,
            failure: null,
            startedAt: new DateTimeImmutable,
            finishedAt: null,
            heartbeatAt: new DateTimeImmutable,
        );

        $this->attemptsTable()->insert([
            'id' => $attempt->id,
            'run_id' => $attempt->runId,
            'number' => $attempt->number,
            'status' => $attempt->status->value,
            'checkpoint' => $attempt->checkpoint === [] ? null : json_encode($attempt->checkpoint, JSON_THROW_ON_ERROR),
            'stats' => $attempt->stats === [] ? null : json_encode($attempt->stats, JSON_THROW_ON_ERROR),
            'failure' => null,
            'started_at' => $attempt->startedAt,
            'heartbeat_at' => $attempt->heartbeatAt,
            'finished_at' => null,
        ]);

        return $attempt;
    }

    /**
     * @param  array<string, mixed>|null  $checkpoint
     */
    public function queueAttempt(IngestionRun $run, QueueDispatchPolicy $policy, ?array $checkpoint = null): IngestionAttempt
    {
        $currentRun = $this->find($run->id);

        if ($currentRun === null
            || in_array($currentRun->status, [RunStatus::Canceled, RunStatus::Completed], true)
            || ($currentRun->status === RunStatus::Failed && $currentRun->failure?->retryable !== true)
        ) {
            throw InvalidRunTransition::from(($currentRun ?? $run)->status, RunStatus::Pending);
        }

        $active = $this->attemptsTable()
            ->where('run_id', $run->id)
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->orderByDesc('number')
            ->first();

        if ($active !== null) {
            return $this->hydrateAttempt($active);
        }

        $number = ((int) $this->attemptsTable()->where('run_id', $run->id)->max('number')) + 1;
        $id = (string) Str::ulid();
        $tags = array_values(array_filter([
            $run->connectorId === null ? null : 'connector:'.$run->connectorId,
            $run->sourceInstallationId === null ? null : 'source-installation:'.$run->sourceInstallationId,
            'run:'.$run->id,
            'attempt:'.$id,
        ]));
        $attempt = new IngestionAttempt(
            id: $id,
            runId: $run->id,
            number: $number,
            status: RunStatus::Pending,
            checkpoint: $checkpoint ?? $run->checkpoint,
            stats: $run->stats,
            failure: null,
            startedAt: null,
            finishedAt: null,
            queue: $policy->queue,
            priority: $policy->priority,
            tags: $tags,
            dispatchPolicy: $policy,
            queuedAt: new DateTimeImmutable,
        );

        $this->attemptsTable()->insert([
            'id' => $attempt->id,
            'run_id' => $attempt->runId,
            'number' => $attempt->number,
            'queue' => $attempt->queue?->value,
            'priority' => $attempt->priority,
            'tags' => json_encode($attempt->tags, JSON_THROW_ON_ERROR),
            'dispatch_policy' => json_encode($policy->toArray(), JSON_THROW_ON_ERROR),
            'status' => $attempt->status->value,
            'checkpoint' => $attempt->checkpoint === [] ? null : json_encode($attempt->checkpoint, JSON_THROW_ON_ERROR),
            'stats' => $attempt->stats === [] ? null : json_encode($attempt->stats, JSON_THROW_ON_ERROR),
            'failure' => null,
            'queued_at' => $attempt->queuedAt,
            'started_at' => null,
            'heartbeat_at' => null,
            'finished_at' => null,
        ]);

        return $attempt;
    }

    public function retryAttempt(
        IngestionRun $run,
        IngestionAttempt $failedAttempt,
        string $reason,
        ?string $partitionKey = null,
        ?DateTimeImmutable $now = null,
        ?QueueDispatchPolicy $policy = null,
    ): IngestionAttempt {
        $currentRun = $this->find($run->id);
        $currentAttempt = $this->attempt($failedAttempt->id);
        $now ??= new DateTimeImmutable;

        if ($currentRun === null || $currentAttempt === null || $currentAttempt->runId !== $currentRun->id) {
            throw new RetryRejected('attempt_not_found', 'The failed attempt does not belong to this run.');
        }

        if (! in_array($currentAttempt->status, [RunStatus::Failed, RunStatus::Partial], true)) {
            throw new RetryRejected('attempt_not_failed', 'Only a failed or partial attempt can be retried.');
        }

        if ($currentAttempt->failure?->retryable !== true) {
            throw new RetryRejected('failure_not_retryable', 'The attempt failure is not retryable.');
        }

        if ($currentAttempt->backoffUntil !== null && $currentAttempt->backoffUntil > $now) {
            throw new RetryRejected('backoff_active', 'The attempt backoff period has not elapsed.');
        }

        if (trim($reason) === '') {
            throw new RetryRejected('reason_required', 'A retry reason is required.');
        }

        if ($partitionKey !== null && ! $this->hasRemainingPartition($currentRun, $partitionKey)) {
            throw new RetryRejected('partition_not_remaining', 'The requested partition is not listed as remaining work.');
        }

        $existing = $this->attemptsTable()->where('retry_of_id', $currentAttempt->id)->first();

        if ($existing !== null) {
            return $this->hydrateAttempt($existing);
        }

        $number = ((int) $this->attemptsTable()->where('run_id', $currentRun->id)->max('number')) + 1;
        $policy ??= QueueDispatchPolicy::forRun($currentRun);
        $id = (string) Str::ulid();
        $tags = array_values(array_filter([
            $currentRun->connectorId === null ? null : 'connector:'.$currentRun->connectorId,
            $currentRun->sourceInstallationId === null ? null : 'source-installation:'.$currentRun->sourceInstallationId,
            'run:'.$currentRun->id,
            'attempt:'.$id,
        ]));
        $attempt = new IngestionAttempt(
            id: $id,
            runId: $currentRun->id,
            number: $number,
            status: RunStatus::Pending,
            checkpoint: $currentRun->checkpoint,
            stats: $currentRun->stats,
            failure: null,
            startedAt: null,
            finishedAt: null,
            retryOfId: $currentAttempt->id,
            retryReason: $reason,
            partitionKey: $partitionKey,
            queue: $policy->queue,
            priority: $policy->priority,
            tags: $tags,
            dispatchPolicy: $policy,
            queuedAt: $now,
        );

        $this->attemptsTable()->insert([
            'id' => $attempt->id,
            'run_id' => $attempt->runId,
            'retry_of_id' => $attempt->retryOfId,
            'retry_reason' => $attempt->retryReason,
            'partition_key' => $attempt->partitionKey,
            'number' => $attempt->number,
            'queue' => $attempt->queue?->value,
            'priority' => $attempt->priority,
            'tags' => json_encode($attempt->tags, JSON_THROW_ON_ERROR),
            'dispatch_policy' => json_encode($policy->toArray(), JSON_THROW_ON_ERROR),
            'status' => $attempt->status->value,
            'checkpoint' => $attempt->checkpoint === [] ? null : json_encode($attempt->checkpoint, JSON_THROW_ON_ERROR),
            'stats' => $attempt->stats === [] ? null : json_encode($attempt->stats, JSON_THROW_ON_ERROR),
            'failure' => null,
            'queued_at' => $attempt->queuedAt,
            'started_at' => null,
            'finished_at' => null,
        ]);
        $this->failuresTable()->where('attempt_id', $currentAttempt->id)->whereNull('resolved_at')->update([
            'resolved_at' => $now,
        ]);

        return $attempt;
    }

    public function retryFor(IngestionAttempt $attempt): ?IngestionAttempt
    {
        $row = $this->attemptsTable()->where('retry_of_id', $attempt->id)->first();

        return $row === null ? null : $this->hydrateAttempt($row);
    }

    public function recordQueueReceipt(IngestionAttempt $attempt, QueueReceipt $receipt): IngestionAttempt
    {
        $this->attemptsTable()->where('id', $attempt->id)->where('status', RunStatus::Pending->value)->update([
            'queue_job_id' => $receipt->jobId,
        ]);

        return $this->attempt($attempt->id) ?? $attempt;
    }

    public function failQueuedAttempt(IngestionAttempt $attempt, Throwable $failure): void
    {
        $runFailure = new RunFailure(
            'queue_dispatch',
            $failure->getMessage(),
            true,
            ['exception' => $failure::class],
            FailureOrigin::Queue,
        );

        $this->attemptsTable()->where('id', $attempt->id)->where('status', RunStatus::Pending->value)->update([
            'status' => RunStatus::Failed->value,
            'failure' => json_encode($runFailure->toArray(), JSON_THROW_ON_ERROR),
            'retryable' => true,
            'error_class' => $failure::class,
            'error_message' => $failure->getMessage(),
            'finished_at' => Carbon::now(),
        ]);

        $run = $this->find($attempt->runId);

        if ($run !== null) {
            $this->recordFailure($run, $attempt, $runFailure);
        }
    }

    public function startQueuedAttempt(IngestionAttempt $attempt, string $workerId): IngestionAttempt
    {
        if (trim($workerId) === '') {
            throw new \InvalidArgumentException('A queued attempt requires a stable worker identity.');
        }

        $current = $this->attempt($attempt->id);

        if ($current === null || $current->status !== RunStatus::Pending) {
            throw InvalidRunTransition::from(($current ?? $attempt)->status, RunStatus::Running);
        }

        $run = $this->find($current->runId);

        if ($run === null) {
            throw InvalidRunTransition::from($current->status, RunStatus::Running);
        }

        $startedAt = new DateTimeImmutable;
        if ($run->status !== RunStatus::Running) {
            $this->resume($run);
        }
        $this->attemptsTable()->where('id', $current->id)->update([
            'status' => RunStatus::Running->value,
            'worker_id' => $workerId,
            'started_at' => $startedAt,
            'heartbeat_at' => $startedAt,
        ]);

        return $this->attempt($current->id) ?? $current;
    }

    public function heartbeat(IngestionAttempt $attempt, ?DateTimeImmutable $at = null): IngestionAttempt
    {
        $current = $this->attempt($attempt->id);

        if ($current === null || $current->status !== RunStatus::Running) {
            throw InvalidRunTransition::from(($current ?? $attempt)->status, RunStatus::Running);
        }

        $this->attemptsTable()->where('id', $current->id)->update([
            'heartbeat_at' => $at ?? new DateTimeImmutable,
        ]);

        return $this->attempt($current->id) ?? $current;
    }

    /**
     * @return list<string>
     */
    public function expireStaleAttempts(DateTimeImmutable $before): array
    {
        $expired = [];
        $rows = $this->attemptsTable()
            ->where('status', RunStatus::Running->value)
            ->where('heartbeat_at', '<=', $before)
            ->get();

        foreach ($rows as $row) {
            $attempt = $this->hydrateAttempt($row);
            $run = $this->find($attempt->runId);

            if ($run === null) {
                continue;
            }

            $failure = new RunFailure(
                'heartbeat_timeout',
                'The ingestion worker heartbeat expired.',
                true,
                ['last_heartbeat_at' => $attempt->heartbeatAt?->format(DATE_ATOM)],
                FailureOrigin::Queue,
            );
            $this->failAttempt($run, $attempt, $failure);
            $this->attemptsTable()->where('id', $attempt->id)->update([
                'error_class' => 'heartbeat_timeout',
                'error_message' => $failure->message,
            ]);
            $expired[] = $attempt->id;
        }

        return $expired;
    }

    public function attempt(string $id): ?IngestionAttempt
    {
        $row = $this->attemptsTable()->where('id', $id)->first();

        return $row === null ? null : $this->hydrateAttempt($row);
    }

    public function activeAttempt(IngestionRun $run): ?IngestionAttempt
    {
        $row = $this->attemptsTable()
            ->where('run_id', $run->id)
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->orderByDesc('number')
            ->first();

        return $row === null ? null : $this->hydrateAttempt($row);
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, int|float>  $stats
     * @param  list<string>  $acceptedReferences
     */
    public function recordProgress(
        IngestionRun $run,
        IngestionAttempt $attempt,
        array $checkpoint,
        array $stats,
        array $acceptedReferences = [],
    ): void {
        $this->assertActiveAttempt($run, $attempt);
        $references = $this->mergeAcceptedReferences($run, $acceptedReferences);

        $this->attemptsTable()->where('id', $attempt->id)->update([
            'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
        ]);
        $this->table()->where('id', $run->id)->update([
            'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'accepted_references' => json_encode($references, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param  array<string, int|float>  $stats
     * @param  list<string>  $acceptedReferences
     */
    public function succeedAttempt(
        IngestionRun $run,
        IngestionAttempt $attempt,
        array $stats,
        array $acceptedReferences = [],
    ): void {
        $this->assertActiveAttempt($run, $attempt);
        $this->assertTransition($run, RunStatus::Completed);
        $references = $this->mergeAcceptedReferences($run, $acceptedReferences);
        $finishedAt = Carbon::now();

        $this->attemptsTable()->where('id', $attempt->id)->update([
            'status' => RunStatus::Completed->value,
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'failure' => null,
            'heartbeat_at' => $finishedAt,
            'finished_at' => $finishedAt,
        ]);
        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Completed->value,
            'completeness' => RunCompleteness::Complete->value,
            'remaining_work' => null,
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'error' => null,
            'failure' => null,
            'accepted_references' => json_encode($references, JSON_THROW_ON_ERROR),
            'finished_at' => $finishedAt,
        ]);
    }

    /**
     * @param  array<string, int|float>  $stats
     * @param  list<string>  $acceptedReferences
     * @param  list<array<string, mixed>>  $remainingWork
     */
    public function failAttempt(
        IngestionRun $run,
        IngestionAttempt $attempt,
        RunFailure $failure,
        array $stats = [],
        array $acceptedReferences = [],
        bool $partial = false,
        array $remainingWork = [],
        int $warningCount = 0,
        int $errorCount = 1,
        ?DateTimeImmutable $backoffUntil = null,
        ?string $partitionKey = null,
    ): void {
        $this->assertActiveAttempt($run, $attempt);
        $status = $partial ? RunStatus::Partial : RunStatus::Failed;
        $this->assertTransition($run, $status);
        $completeness = $partial ? RunCompleteness::Partial : RunCompleteness::Incomplete;
        $references = $this->mergeAcceptedReferences($run, $acceptedReferences);
        $finishedAt = Carbon::now();
        $encodedFailure = json_encode($failure->toArray(), JSON_THROW_ON_ERROR);

        $this->attemptsTable()->where('id', $attempt->id)->update([
            'status' => $status->value,
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'failure' => $encodedFailure,
            'retryable' => $failure->retryable,
            'backoff_until' => $backoffUntil,
            'error_class' => $failure->kind,
            'error_message' => $failure->message,
            'heartbeat_at' => $finishedAt,
            'finished_at' => $finishedAt,
        ]);
        $this->recordFailure($run, $attempt, $failure, $partitionKey);
        $this->table()->where('id', $run->id)->update([
            'status' => $status->value,
            'completeness' => $completeness->value,
            'remaining_work' => $remainingWork === [] ? null : json_encode($remainingWork, JSON_THROW_ON_ERROR),
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'warning_count' => $warningCount,
            'error_count' => $errorCount,
            'error' => $failure->message,
            'failure' => $encodedFailure,
            'accepted_references' => json_encode($references, JSON_THROW_ON_ERROR),
            'finished_at' => $finishedAt,
        ]);
    }

    /**
     * @return list<IngestionAttempt>
     */
    public function attempts(IngestionRun $run): array
    {
        return array_values($this->attemptsTable()
            ->where('run_id', $run->id)
            ->orderBy('number')
            ->get()
            ->map(fn (stdClass $row): IngestionAttempt => $this->hydrateAttempt($row))
            ->all());
    }

    /**
     * @return list<IngestionFailure>
     */
    public function failures(IngestionRun $run): array
    {
        return array_values($this->failuresTable()
            ->where('run_id', $run->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): IngestionFailure => $this->hydrateFailure($row))
            ->all());
    }

    /**
     * @param  list<array<string, mixed>>  $remainingWork
     */
    public function cancel(IngestionRun $run, string $message, array $remainingWork = []): void
    {
        $this->assertTransition($run, RunStatus::Canceled);
        $failure = new RunFailure('canceled', $message, false);
        $finishedAt = Carbon::now();

        $this->attemptsTable()
            ->where('run_id', $run->id)
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->update([
                'status' => RunStatus::Canceled->value,
                'failure' => json_encode($failure->toArray(), JSON_THROW_ON_ERROR),
                'error_class' => $failure->kind,
                'error_message' => $failure->message,
                'finished_at' => $finishedAt,
            ]);

        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Canceled->value,
            'completeness' => RunCompleteness::Incomplete->value,
            'remaining_work' => $remainingWork === [] ? null : json_encode($remainingWork, JSON_THROW_ON_ERROR),
            'failure' => json_encode($failure->toArray(), JSON_THROW_ON_ERROR),
            'error' => $message,
            'error_count' => 1,
            'finished_at' => $finishedAt,
        ]);
        $this->recordFailure($run, null, $failure);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    public function complete(IngestionRun $run, array $stats): void
    {
        $this->assertTransition($run, RunStatus::Completed);

        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Completed->value,
            'completeness' => RunCompleteness::Complete->value,
            'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
            'error' => null,
            'finished_at' => Carbon::now(),
        ]);
    }

    public function interrupt(IngestionRun $run, string $error): void
    {
        $this->assertTransition($run, RunStatus::Interrupted);

        $failure = new RunFailure('interrupted', $error, true);
        $finishedAt = Carbon::now();

        $this->attemptsTable()
            ->where('run_id', $run->id)
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->update([
                'status' => RunStatus::Interrupted->value,
                'failure' => json_encode($failure->toArray(), JSON_THROW_ON_ERROR),
                'retryable' => true,
                'error_class' => $failure->kind,
                'error_message' => $failure->message,
                'finished_at' => $finishedAt,
            ]);

        $this->table()->where('id', $run->id)->update([
            'status' => RunStatus::Interrupted->value,
            'completeness' => RunCompleteness::Incomplete->value,
            'error' => $error,
            'failure' => json_encode($failure->toArray(), JSON_THROW_ON_ERROR),
            'error_count' => 1,
            'finished_at' => $finishedAt,
        ]);
        $this->recordFailure($run, null, $failure);
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    public function checkpoint(IngestionRun $run, array $checkpoint): void
    {
        $this->table()->where('id', $run->id)->update([
            'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
        ]);
    }

    private function insert(IngestionRun $run): void
    {
        $this->table()->insert([
            'id' => $run->id,
            'connector_id' => $run->connectorId,
            'source_installation_id' => $run->sourceInstallationId,
            'legacy_reference' => $run->legacyReference,
            'idempotency_key' => $run->idempotencyKey,
            'source_reference' => $run->sourceReference,
            'capability' => $run->capability->value,
            'trigger' => $run->trigger->value,
            'requested_by' => $run->requestedBy,
            'authorization_decision' => $run->authorizationDecision,
            'status' => $run->status->value,
            'completeness' => $run->completeness->value,
            'parameters' => json_encode($run->parameters, JSON_THROW_ON_ERROR),
            'requested_at' => $run->requestedAt,
            'checkpoint' => $run->checkpoint === [] ? null : json_encode($run->checkpoint, JSON_THROW_ON_ERROR),
            'remaining_work' => $run->remainingWork === [] ? null : json_encode($run->remainingWork, JSON_THROW_ON_ERROR),
            'stats' => $run->stats === [] ? null : json_encode($run->stats, JSON_THROW_ON_ERROR),
            'warning_count' => $run->warningCount,
            'error_count' => $run->errorCount,
            'error' => $run->failure?->message,
            'failure' => $run->failure === null ? null : json_encode($run->failure->toArray(), JSON_THROW_ON_ERROR),
            'accepted_references' => $run->acceptedReferences === [] ? null : json_encode($run->acceptedReferences, JSON_THROW_ON_ERROR),
            'started_at' => $run->startedAt,
            'finished_at' => $run->finishedAt,
        ]);
    }

    private function hydrate(stdClass $row): IngestionRun
    {
        $parameters = json_decode((string) $row->parameters, true, 512, JSON_THROW_ON_ERROR);
        $checkpoint = $row->checkpoint === null ? [] : json_decode((string) $row->checkpoint, true, 512, JSON_THROW_ON_ERROR);
        $stats = $row->stats === null ? [] : json_decode((string) $row->stats, true, 512, JSON_THROW_ON_ERROR);
        $remainingWork = $row->remaining_work === null ? [] : json_decode((string) $row->remaining_work, true, 512, JSON_THROW_ON_ERROR);
        $failure = $row->failure === null ? null : json_decode((string) $row->failure, true, 512, JSON_THROW_ON_ERROR);
        $acceptedReferences = $row->accepted_references === null ? [] : json_decode((string) $row->accepted_references, true, 512, JSON_THROW_ON_ERROR);

        return new IngestionRun(
            id: (string) $row->id,
            sourceReference: (string) $row->source_reference,
            capability: Capability::from((string) $row->capability),
            status: RunStatus::from((string) $row->status),
            parameters: is_array($parameters) ? $parameters : [],
            startedAt: $row->started_at === null ? null : new DateTimeImmutable((string) $row->started_at),
            connectorId: $row->connector_id === null ? null : (string) $row->connector_id,
            sourceInstallationId: $row->source_installation_id === null ? null : (string) $row->source_installation_id,
            legacyReference: $row->legacy_reference === null ? null : (string) $row->legacy_reference,
            idempotencyKey: $row->idempotency_key === null ? null : (string) $row->idempotency_key,
            completeness: RunCompleteness::from((string) $row->completeness),
            checkpoint: is_array($checkpoint) ? $checkpoint : [],
            stats: is_array($stats) ? $stats : [],
            failure: is_array($failure) ? RunFailure::fromArray($failure) : null,
            acceptedReferences: is_array($acceptedReferences) ? array_values(array_map(strval(...), $acceptedReferences)) : [],
            finishedAt: $row->finished_at === null ? null : new DateTimeImmutable((string) $row->finished_at),
            trigger: IngestionTrigger::from((string) $row->trigger),
            requestedBy: $row->requested_by === null ? null : (string) $row->requested_by,
            authorizationDecision: $row->authorization_decision === null ? null : (string) $row->authorization_decision,
            requestedAt: $row->requested_at === null ? null : new DateTimeImmutable((string) $row->requested_at),
            remainingWork: is_array($remainingWork) ? array_values($remainingWork) : [],
            warningCount: (int) $row->warning_count,
            errorCount: (int) $row->error_count,
        );
    }

    private function hydrateFailure(stdClass $row): IngestionFailure
    {
        $details = $row->details === null ? [] : json_decode((string) $row->details, true, 512, JSON_THROW_ON_ERROR);

        return new IngestionFailure(
            id: (string) $row->id,
            runId: (string) $row->run_id,
            attemptId: $row->attempt_id === null ? null : (string) $row->attempt_id,
            partitionKey: $row->partition_key === null ? null : (string) $row->partition_key,
            origin: FailureOrigin::from((string) $row->origin),
            category: (string) $row->category,
            message: (string) $row->message,
            details: is_array($details) ? $details : [],
            occurredAt: new DateTimeImmutable((string) $row->occurred_at),
            resolvedAt: $row->resolved_at === null ? null : new DateTimeImmutable((string) $row->resolved_at),
        );
    }

    private function recordFailure(
        IngestionRun $run,
        ?IngestionAttempt $attempt,
        RunFailure $failure,
        ?string $partitionKey = null,
    ): void {
        $this->failuresTable()->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $run->id,
            'attempt_id' => $attempt?->id,
            'partition_key' => $partitionKey,
            'origin' => $failure->origin->value,
            'category' => $failure->kind,
            'message' => $failure->message,
            'details' => $failure->details === [] ? null : json_encode($failure->details, JSON_THROW_ON_ERROR),
            'occurred_at' => Carbon::now(),
        ]);
    }

    private function hydrateAttempt(stdClass $row): IngestionAttempt
    {
        $checkpoint = $row->checkpoint === null ? [] : json_decode((string) $row->checkpoint, true, 512, JSON_THROW_ON_ERROR);
        $stats = $row->stats === null ? [] : json_decode((string) $row->stats, true, 512, JSON_THROW_ON_ERROR);
        $failure = $row->failure === null ? null : json_decode((string) $row->failure, true, 512, JSON_THROW_ON_ERROR);
        $tags = $row->tags === null ? [] : json_decode((string) $row->tags, true, 512, JSON_THROW_ON_ERROR);
        $dispatchPolicy = $row->dispatch_policy === null ? null : json_decode((string) $row->dispatch_policy, true, 512, JSON_THROW_ON_ERROR);

        return new IngestionAttempt(
            id: (string) $row->id,
            runId: (string) $row->run_id,
            number: (int) $row->number,
            status: RunStatus::from((string) $row->status),
            checkpoint: is_array($checkpoint) ? $checkpoint : [],
            stats: is_array($stats) ? $stats : [],
            failure: is_array($failure) ? RunFailure::fromArray($failure) : null,
            startedAt: $row->started_at === null ? null : new DateTimeImmutable((string) $row->started_at),
            finishedAt: $row->finished_at === null ? null : new DateTimeImmutable((string) $row->finished_at),
            queue: $row->queue === null ? null : QueueClass::from((string) $row->queue),
            priority: $row->priority === null ? null : (int) $row->priority,
            tags: is_array($tags) ? array_values(array_map(strval(...), $tags)) : [],
            dispatchPolicy: is_array($dispatchPolicy) ? QueueDispatchPolicy::fromArray($dispatchPolicy) : null,
            queueJobId: $row->queue_job_id === null ? null : (string) $row->queue_job_id,
            workerId: $row->worker_id === null ? null : (string) $row->worker_id,
            queuedAt: $row->queued_at === null ? null : new DateTimeImmutable((string) $row->queued_at),
            heartbeatAt: $row->heartbeat_at === null ? null : new DateTimeImmutable((string) $row->heartbeat_at),
            retryOfId: $row->retry_of_id === null ? null : (string) $row->retry_of_id,
            retryReason: $row->retry_reason === null ? null : (string) $row->retry_reason,
            partitionKey: $row->partition_key === null ? null : (string) $row->partition_key,
            retryable: $row->retryable === null ? null : (bool) $row->retryable,
            backoffUntil: $row->backoff_until === null ? null : new DateTimeImmutable((string) $row->backoff_until),
        );
    }

    private function hasRemainingPartition(IngestionRun $run, string $partitionKey): bool
    {
        foreach ($run->remainingWork as $work) {
            if (($work['partition_key'] ?? null) === $partitionKey) {
                return true;
            }
        }

        return false;
    }

    private function assertActiveAttempt(IngestionRun $run, IngestionAttempt $attempt): void
    {
        if ($attempt->runId !== $run->id || $attempt->status !== RunStatus::Running) {
            throw InvalidRunTransition::from($attempt->status, RunStatus::Running);
        }

        $active = $this->attemptsTable()
            ->where('id', $attempt->id)
            ->where('run_id', $run->id)
            ->where('status', RunStatus::Running->value)
            ->exists();

        if (! $active) {
            throw InvalidRunTransition::from($attempt->status, RunStatus::Running);
        }
    }

    private function assertTransition(IngestionRun $run, RunStatus $status): void
    {
        $current = $this->find($run->id);

        if ($current === null) {
            throw InvalidRunTransition::from($run->status, $status);
        }

        if (! $current->status->canTransitionTo($status)) {
            throw InvalidRunTransition::from($current->status, $status);
        }
    }

    /**
     * @param  list<string>  $references
     * @return list<string>
     */
    private function mergeAcceptedReferences(IngestionRun $run, array $references): array
    {
        $current = $this->find($run->id);

        if ($current === null) {
            return array_values(array_unique([...$run->acceptedReferences, ...$references]));
        }

        return array_values(array_unique([...$current->acceptedReferences, ...$references]));
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_ingestion_runs');
    }

    private function attemptsTable(): Builder
    {
        return $this->connection->table('aleph_ingestion_attempts');
    }

    private function failuresTable(): Builder
    {
        return $this->connection->table('aleph_ingestion_failures');
    }
}
