<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class SourceStreamStatuses
{
    public function __construct(
        private ConnectionInterface $connection,
        private SourceStreams $streams,
        private IngestionRuns $runs,
        private IngestionCheckpoints $checkpoints,
    ) {}

    public function configure(SourceStream $stream, FreshnessExpectation $expectation, DateTimeImmutable $at): SourceStreamStatus
    {
        $this->table()->updateOrInsert(
            ['source_stream_id' => $stream->id],
            [
                'expected_interval_seconds' => $expectation->intervalSeconds,
                'stale_after_seconds' => $expectation->staleAfterSeconds,
                'freshness_status' => FreshnessStatus::NeverSynchronized->value,
                'updated_at' => $at,
            ],
        );

        return $this->find($stream, $at);
    }

    public function project(
        SourceStream $stream,
        IngestionRun $run,
        IngestionAttempt $attempt,
        FreshnessExpectation $expectation,
        DateTimeImmutable $at,
    ): SourceStreamStatus {
        $currentRun = $this->runs->find($run->id);
        $currentAttempt = $this->runs->attempt($attempt->id);

        if ($currentRun === null
            || $currentAttempt === null
            || $currentAttempt->runId !== $currentRun->id
            || $stream->sourceInstallationId !== $currentRun->sourceInstallationId
        ) {
            throw new \InvalidArgumentException('Stream, run, and attempt must belong to the same source execution.');
        }

        $values = [
            'last_attempt_id' => $currentAttempt->id,
            'expected_interval_seconds' => $expectation->intervalSeconds,
            'stale_after_seconds' => $expectation->staleAfterSeconds,
            'updated_at' => $at,
        ];

        if ($currentRun->status === RunStatus::Completed && $currentAttempt->status === RunStatus::Completed) {
            $lastSuccessAt = $currentRun->finishedAt ?? $at;
            $values = [
                ...$values,
                'last_successful_run_id' => $currentRun->id,
                'last_success_at' => $lastSuccessAt,
                'accepted_through_at' => $this->checkpoints->acceptedThroughAt($stream, $currentRun),
                'next_due_at' => $lastSuccessAt->modify("+{$expectation->intervalSeconds} seconds"),
                'freshness_status' => FreshnessStatus::Healthy->value,
            ];
        }

        $this->table()->updateOrInsert(['source_stream_id' => $stream->id], $values);

        return $this->find($stream, $at);
    }

    public function schedule(SourceStream $stream, DateTimeImmutable $nextDueAt, DateTimeImmutable $at): SourceStreamStatus
    {
        $this->table()->updateOrInsert(
            ['source_stream_id' => $stream->id],
            ['next_due_at' => $nextDueAt, 'updated_at' => $at],
        );

        return $this->find($stream, $at);
    }

    public function find(SourceStream $stream, DateTimeImmutable $now): SourceStreamStatus
    {
        $row = $this->table()->where('source_stream_id', $stream->id)->first();

        if (! $row instanceof stdClass) {
            return new SourceStreamStatus(
                $stream->id,
                null,
                null,
                null,
                null,
                null,
                FreshnessStatus::NeverSynchronized,
                null,
            );
        }

        return $this->hydrate($row, $now);
    }

    /**
     * @return list<SourceStreamStatus>
     */
    public function allForInstallation(string $sourceInstallationId, DateTimeImmutable $now): array
    {
        return array_map(
            fn (SourceStream $stream): SourceStreamStatus => $this->find($stream, $now),
            $this->streams->active($sourceInstallationId),
        );
    }

    private function hydrate(stdClass $row, DateTimeImmutable $now): SourceStreamStatus
    {
        $lastSuccessAt = $row->last_success_at === null ? null : new DateTimeImmutable((string) $row->last_success_at);
        $nextDueAt = $row->next_due_at === null ? null : new DateTimeImmutable((string) $row->next_due_at);
        $expectation = $row->expected_interval_seconds === null || $row->stale_after_seconds === null
            ? null
            : new FreshnessExpectation((int) $row->expected_interval_seconds, (int) $row->stale_after_seconds);

        return new SourceStreamStatus(
            sourceStreamId: (string) $row->source_stream_id,
            lastAttemptId: $row->last_attempt_id === null ? null : (string) $row->last_attempt_id,
            lastSuccessfulRunId: $row->last_successful_run_id === null ? null : (string) $row->last_successful_run_id,
            lastSuccessAt: $lastSuccessAt,
            acceptedThroughAt: $row->accepted_through_at === null ? null : new DateTimeImmutable((string) $row->accepted_through_at),
            nextDueAt: $nextDueAt,
            freshness: $this->freshness($lastSuccessAt, $nextDueAt, $expectation, $now),
            expectation: $expectation,
        );
    }

    private function freshness(
        ?DateTimeImmutable $lastSuccessAt,
        ?DateTimeImmutable $nextDueAt,
        ?FreshnessExpectation $expectation,
        DateTimeImmutable $now,
    ): FreshnessStatus {
        if ($lastSuccessAt === null) {
            return FreshnessStatus::NeverSynchronized;
        }

        if ($nextDueAt === null || $now <= $nextDueAt) {
            return FreshnessStatus::Healthy;
        }

        if ($expectation !== null && $now <= $lastSuccessAt->modify("+{$expectation->staleAfterSeconds} seconds")) {
            return FreshnessStatus::Due;
        }

        return FreshnessStatus::Stale;
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_source_stream_status');
    }
}
