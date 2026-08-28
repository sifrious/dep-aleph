<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use stdClass;

final readonly class NormalizationAttempts
{
    public function __construct(private ConnectionInterface $connection) {}

    public function record(NormalizationAttempt $attempt): NormalizationAttempt
    {
        $this->table()->insert([
            'id' => $attempt->id,
            'ingestion_attempt_id' => $attempt->ingestionAttemptId,
            'normalizer' => $attempt->normalizer->id,
            'normalizer_version' => $attempt->normalizer->version,
            'candidate_schema' => $attempt->schema->name,
            'candidate_schema_version' => $attempt->schema->version,
            'input_hash' => $attempt->inputHash,
            'source_reference' => $attempt->sourceReference,
            'status' => $attempt->status->value,
            'candidate_count' => $attempt->candidateCount,
            'cached' => $attempt->cached,
            'error_code' => $attempt->errorCode,
            'error' => $attempt->error,
            'started_at' => $attempt->startedAt,
            'completed_at' => $attempt->completedAt,
            'duration_ms' => $attempt->durationMs,
        ]);

        return $attempt;
    }

    public function find(string $id): ?NormalizationAttempt
    {
        $row = $this->table()->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * @return list<NormalizationAttempt>
     */
    public function forInput(string $inputHash): array
    {
        return $this->hydrateAll(
            $this->table()->where('input_hash', $inputHash)->orderBy('started_at')
        );
    }

    /**
     * @return list<NormalizationAttempt>
     */
    public function forNormalizer(NormalizerIdentity $normalizer): array
    {
        return $this->hydrateAll(
            $this->table()
                ->where('normalizer', $normalizer->id)
                ->where('normalizer_version', $normalizer->version)
                ->orderBy('started_at')
        );
    }

    public function newId(): string
    {
        return (string) Str::ulid();
    }

    /**
     * @return list<NormalizationAttempt>
     */
    private function hydrateAll(Builder $query): array
    {
        return array_values(array_map(
            fn (stdClass $row): NormalizationAttempt => $this->hydrate($row),
            $query->get()->all(),
        ));
    }

    private function hydrate(stdClass $row): NormalizationAttempt
    {
        return new NormalizationAttempt(
            id: $row->id,
            ingestionAttemptId: $row->ingestion_attempt_id,
            normalizer: new NormalizerIdentity($row->normalizer, (int) $row->normalizer_version),
            schema: new CandidateSchema($row->candidate_schema, (int) $row->candidate_schema_version),
            inputHash: $row->input_hash,
            sourceReference: $row->source_reference,
            status: NormalizationStatus::from($row->status),
            candidateCount: (int) $row->candidate_count,
            cached: (bool) $row->cached,
            errorCode: $row->error_code,
            error: $row->error,
            startedAt: new DateTimeImmutable((string) $row->started_at),
            completedAt: new DateTimeImmutable((string) $row->completed_at),
            durationMs: (int) $row->duration_ms,
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_normalization_attempts');
    }
}
