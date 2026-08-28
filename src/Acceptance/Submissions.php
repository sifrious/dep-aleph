<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Acceptance;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use stdClass;

final readonly class Submissions
{
    public function __construct(private ConnectionInterface $connection) {}

    public function open(string $idempotencyKey, string $payloadHash, ?string $attemptId): Submission
    {
        $submission = new Submission(
            id: (string) Str::ulid(),
            attemptId: $attemptId,
            idempotencyKey: $idempotencyKey,
            payloadHash: $payloadHash,
            status: SubmissionStatus::Pending,
            acceptedType: null,
            acceptedId: null,
            error: null,
            createdAt: new DateTimeImmutable,
            completedAt: null,
        );

        $this->table()->insert([
            'id' => $submission->id,
            'attempt_id' => $submission->attemptId,
            'idempotency_key' => $submission->idempotencyKey,
            'payload_hash' => $submission->payloadHash,
            'status' => $submission->status->value,
            'created_at' => $submission->createdAt,
        ]);

        return $submission;
    }

    public function settle(
        Submission $submission,
        SubmissionStatus $status,
        ?string $acceptedType = null,
        ?string $acceptedId = null,
        ?string $error = null,
    ): Submission {
        $completedAt = new DateTimeImmutable;

        $this->table()->where('id', $submission->id)->update([
            'status' => $status->value,
            'accepted_type' => $acceptedType,
            'accepted_id' => $acceptedId,
            'error' => $error,
            'completed_at' => $completedAt,
        ]);

        return new Submission(
            id: $submission->id,
            attemptId: $submission->attemptId,
            idempotencyKey: $submission->idempotencyKey,
            payloadHash: $submission->payloadHash,
            status: $status,
            acceptedType: $acceptedType,
            acceptedId: $acceptedId,
            error: $error,
            createdAt: $submission->createdAt,
            completedAt: $completedAt,
        );
    }

    public function find(string $id): ?Submission
    {
        $row = $this->table()->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * @return list<Submission>
     */
    public function forKey(string $idempotencyKey): array
    {
        return array_values(array_map(
            fn (stdClass $row): Submission => $this->hydrate($row),
            $this->table()->where('idempotency_key', $idempotencyKey)->orderBy('created_at')->get()->all(),
        ));
    }

    /**
     * @return list<Submission>
     */
    public function retryable(): array
    {
        return array_values(array_map(
            fn (stdClass $row): Submission => $this->hydrate($row),
            $this->table()->whereIn('status', [
                SubmissionStatus::Pending->value,
                SubmissionStatus::TransportFailed->value,
                SubmissionStatus::InFlight->value,
            ])->orderBy('created_at')->get()->all(),
        ));
    }

    private function hydrate(stdClass $row): Submission
    {
        return new Submission(
            id: $row->id,
            attemptId: $row->attempt_id,
            idempotencyKey: $row->idempotency_key,
            payloadHash: $row->payload_hash,
            status: SubmissionStatus::from($row->status),
            acceptedType: $row->accepted_type,
            acceptedId: $row->accepted_id,
            error: $row->error,
            createdAt: new DateTimeImmutable((string) $row->created_at),
            completedAt: $row->completed_at === null ? null : new DateTimeImmutable((string) $row->completed_at),
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_funes_submissions');
    }
}
