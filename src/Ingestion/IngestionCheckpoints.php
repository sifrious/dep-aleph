<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use JsonException;
use Sifrious\Aleph\Acceptance\Submissions;
use stdClass;

final readonly class IngestionCheckpoints
{
    public function __construct(
        private ConnectionInterface $connection,
        private IngestionRuns $runs,
        private Submissions $submissions,
    ) {}

    /**
     * @param  list<string>  $acceptedReferences
     */
    public function commit(
        SourceStream $stream,
        Capability $capability,
        string $partitionKey,
        CheckpointValue $value,
        int $expectedVersion,
        IngestionRun $run,
        array $acceptedReferences,
        ?IngestionAttempt $attempt = null,
    ): IngestionCheckpoint {
        if (! $stream->enabled || ! $this->connection->table('aleph_source_streams')->where('id', $stream->id)->where('enabled', true)->exists()) {
            throw new CheckpointConflict('A disabled source stream cannot advance.');
        }

        if (trim($partitionKey) === '') {
            throw new CheckpointConflict('A checkpoint requires a stable partition key.');
        }

        $commitKey = $this->commitKey($stream, $capability, $partitionKey, $value, $run, $acceptedReferences);
        $replay = $this->findByCommitKey($commitKey);

        if ($replay !== null) {
            return $replay;
        }

        $current = $this->latest($stream, $capability, $partitionKey);
        $currentVersion = $current === null ? 0 : $current->version;

        if ($expectedVersion !== $currentVersion) {
            throw new CheckpointConflict("Expected checkpoint version {$expectedVersion}; current version is {$currentVersion}.");
        }

        $this->validateProgression($current, $value);
        $this->validateAcceptance($run, $capability, $acceptedReferences, $attempt);
        $version = $currentVersion + 1;
        $id = (string) Str::ulid();
        $committedAt = new DateTimeImmutable;
        try {
            $this->table()->insert([
                'id' => $id,
                'commit_key' => $commitKey,
                'source_stream_id' => $stream->id,
                'capability' => $capability->value,
                'partition_key' => $partitionKey,
                'format' => $value->format,
                'serializer_version' => $value->serializerVersion,
                'value' => $value->value,
                'rule' => $value->rule->value,
                'position' => $value->position,
                'version' => $version,
                'accepted_references' => json_encode($acceptedReferences, JSON_THROW_ON_ERROR),
                'run_id' => $run->id,
                'attempt_id' => $attempt?->id,
                'committed_at' => $committedAt,
            ]);
        } catch (QueryException $failure) {
            $replay = $this->findByCommitKey($commitKey);

            if ($replay !== null) {
                return $replay;
            }

            throw new CheckpointConflict('The checkpoint version changed before commit.', previous: $failure);
        }

        return $this->find($id) ?? throw new CheckpointConflict('The checkpoint commit could not be read back.');
    }

    public function latest(SourceStream $stream, Capability $capability, string $partitionKey): ?IngestionCheckpoint
    {
        $row = $this->identity($stream, $capability, $partitionKey)->orderByDesc('version')->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * @return list<IngestionCheckpoint>
     */
    public function history(SourceStream $stream, Capability $capability, string $partitionKey): array
    {
        return array_values($this->identity($stream, $capability, $partitionKey)
            ->orderBy('version')
            ->get()
            ->map(fn (stdClass $row): IngestionCheckpoint => $this->hydrate($row))
            ->all());
    }

    public function find(string $id): ?IngestionCheckpoint
    {
        $row = $this->table()->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function acceptedThroughAt(SourceStream $stream, IngestionRun $run): ?DateTimeImmutable
    {
        $value = $this->table()
            ->where('source_stream_id', $stream->id)
            ->where('run_id', $run->id)
            ->max('committed_at');

        return $value === null ? null : new DateTimeImmutable((string) $value);
    }

    private function validateProgression(?IngestionCheckpoint $current, CheckpointValue $next): void
    {
        if ($next->rule !== CheckpointRule::Monotonic || $current === null) {
            return;
        }

        if ($current->value->rule !== CheckpointRule::Monotonic || $next->position <= $current->value->position) {
            throw new CheckpointConflict('A monotonic checkpoint must advance beyond its current position.');
        }
    }

    /**
     * @param  list<string>  $acceptedReferences
     */
    private function validateAcceptance(
        IngestionRun $run,
        Capability $capability,
        array $acceptedReferences,
        ?IngestionAttempt $attempt,
    ): void {
        $current = $this->runs->find($run->id);

        if ($current === null || $acceptedReferences === []) {
            throw new CheckpointConflict('A checkpoint must identify accepted Funes history from its run.');
        }

        if ($current->capability !== $capability || ($attempt !== null && $attempt->runId !== $current->id)) {
            throw new CheckpointConflict('Checkpoint capability and attempt must belong to the committing run.');
        }

        foreach ($acceptedReferences as $reference) {
            if (! in_array($reference, $current->acceptedReferences, true) || ! $this->submissions->accepted($reference)) {
                throw new CheckpointConflict("Funes has not accepted [{$reference}] through this run.");
            }
        }
    }

    /**
     * @param  list<string>  $acceptedReferences
     */
    private function commitKey(
        SourceStream $stream,
        Capability $capability,
        string $partitionKey,
        CheckpointValue $value,
        IngestionRun $run,
        array $acceptedReferences,
    ): string {
        return hash('sha256', json_encode([
            $stream->id,
            $capability->value,
            $partitionKey,
            $value->format,
            $value->serializerVersion,
            $value->value,
            $value->rule->value,
            $value->position,
            $run->id,
            $acceptedReferences,
        ], JSON_THROW_ON_ERROR));
    }

    private function findByCommitKey(string $key): ?IngestionCheckpoint
    {
        $row = $this->table()->where('commit_key', $key)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function identity(SourceStream $stream, Capability $capability, string $partitionKey): Builder
    {
        return $this->table()
            ->where('source_stream_id', $stream->id)
            ->where('capability', $capability->value)
            ->where('partition_key', $partitionKey);
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_ingestion_checkpoints');
    }

    private function hydrate(stdClass $row): IngestionCheckpoint
    {
        $accepted = json_decode((string) $row->accepted_references, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($accepted)) {
            throw new JsonException('Stored checkpoint accepted references must be an array.');
        }

        return new IngestionCheckpoint(
            (string) $row->id,
            (string) $row->source_stream_id,
            Capability::from((string) $row->capability),
            (string) $row->partition_key,
            new CheckpointValue(
                (string) $row->format,
                (string) $row->serializer_version,
                (string) $row->value,
                CheckpointRule::from((string) $row->rule),
                $row->position === null ? null : (int) $row->position,
            ),
            (int) $row->version,
            array_values(array_map(strval(...), $accepted)),
            (string) $row->run_id,
            $row->attempt_id === null ? null : (string) $row->attempt_id,
            new DateTimeImmutable((string) $row->committed_at),
        );
    }
}
