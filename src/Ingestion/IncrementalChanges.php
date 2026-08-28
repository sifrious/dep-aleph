<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Sifrious\Aleph\Acceptance\Submissions;
use stdClass;

final readonly class IncrementalChanges
{
    public function __construct(
        private ConnectionInterface $connection,
        private IngestionRuns $runs,
        private Submissions $submissions,
    ) {}

    public function record(
        SourceStream $stream,
        IngestionRun $run,
        IngestionAttempt $attempt,
        string $partitionKey,
        IncrementalChangeDraft $draft,
        DateTimeImmutable $recordedAt,
    ): ?IncrementalChange {
        if ($draft->kind === ChangeKind::Unchanged) {
            return null;
        }

        $current = $this->runs->find($run->id);
        $observationReference = (string) $draft->observationReference;

        if ($current === null
            || $attempt->runId !== $current->id
            || $stream->sourceInstallationId !== $current->sourceInstallationId
            || ! in_array($observationReference, $current->acceptedReferences, true)
            || ! $this->submissions->accepted($observationReference)
        ) {
            throw new InvalidArgumentException('A material change must belong to the run and reference Funes-accepted history.');
        }

        if (trim($partitionKey) === '') {
            throw new InvalidArgumentException('An incremental change requires a stable partition.');
        }

        $changeKey = hash('sha256', implode("\0", [
            $stream->id,
            $partitionKey,
            $draft->sourceChangeId,
            $draft->kind->value,
            $draft->fingerprint,
        ]));
        $existing = $this->findByKey($changeKey);

        if ($existing !== null) {
            return $existing;
        }

        $id = (string) Str::ulid();
        $this->table()->insert([
            'id' => $id,
            'change_key' => $changeKey,
            'source_stream_id' => $stream->id,
            'run_id' => $current->id,
            'attempt_id' => $attempt->id,
            'partition_key' => $partitionKey,
            'source_change_id' => $draft->sourceChangeId,
            'kind' => $draft->kind->value,
            'resource_reference' => $draft->resourceReference,
            'fingerprint' => $draft->fingerprint,
            'observation_reference' => $observationReference,
            'occurred_at' => $draft->occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        return $this->find($id) ?? throw new InvalidArgumentException('The incremental change could not be read back.');
    }

    /**
     * @return list<IncrementalChange>
     */
    public function forRun(IngestionRun $run): array
    {
        return array_values($this->table()
            ->where('run_id', $run->id)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): IncrementalChange => $this->hydrate($row))
            ->all());
    }

    private function find(string $id): ?IncrementalChange
    {
        $row = $this->table()->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function findByKey(string $key): ?IncrementalChange
    {
        $row = $this->table()->where('change_key', $key)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function hydrate(stdClass $row): IncrementalChange
    {
        return new IncrementalChange(
            id: (string) $row->id,
            sourceStreamId: (string) $row->source_stream_id,
            runId: (string) $row->run_id,
            attemptId: $row->attempt_id === null ? null : (string) $row->attempt_id,
            partitionKey: (string) $row->partition_key,
            sourceChangeId: (string) $row->source_change_id,
            kind: ChangeKind::from((string) $row->kind),
            resourceReference: (string) $row->resource_reference,
            fingerprint: (string) $row->fingerprint,
            observationReference: (string) $row->observation_reference,
            occurredAt: new DateTimeImmutable((string) $row->occurred_at),
            recordedAt: new DateTimeImmutable((string) $row->recorded_at),
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_incremental_changes');
    }
}
