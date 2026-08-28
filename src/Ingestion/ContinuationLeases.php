<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use stdClass;

final readonly class ContinuationLeases
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @param  list<string>  $partitionKeys
     * @return list<ContinuationLease>
     */
    public function acquireMany(
        SourceStream $stream,
        Capability $capability,
        array $partitionKeys,
        IngestionRun $run,
        string $owner,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): array {
        return $this->connection->transaction(function () use ($stream, $capability, $partitionKeys, $run, $owner, $now, $leaseSeconds): array {
            $leases = [];

            foreach ($partitionKeys as $partitionKey) {
                $leases[] = $this->acquire($stream, $capability, $partitionKey, $run, $owner, $now, $leaseSeconds);
            }

            return $leases;
        });
    }

    public function acquire(
        SourceStream $stream,
        Capability $capability,
        string $partitionKey,
        IngestionRun $run,
        string $owner,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): ContinuationLease {
        if (trim($owner) === '' || $leaseSeconds < 1) {
            throw new ResumeRejected('lease_invalid', 'A resume lease requires an owner and positive duration.');
        }

        return $this->connection->transaction(function () use ($stream, $capability, $partitionKey, $run, $owner, $now, $leaseSeconds): ContinuationLease {
            $query = $this->connection->table('aleph_continuation_leases')
                ->where('source_stream_id', $stream->id)
                ->where('capability', $capability->value)
                ->where('partition_key', $partitionKey);
            $row = $query->lockForUpdate()->first();

            if ($row instanceof stdClass && (string) $row->owner === $owner && new DateTimeImmutable((string) $row->expires_at) > $now) {
                return $this->hydrate($row);
            }

            if ($row instanceof stdClass && new DateTimeImmutable((string) $row->expires_at) > $now) {
                throw new ResumeRejected('partition_leased', "Partition [{$partitionKey}] is already leased.");
            }

            $values = [
                'id' => (string) Str::ulid(),
                'source_stream_id' => $stream->id,
                'capability' => $capability->value,
                'partition_key' => $partitionKey,
                'run_id' => $run->id,
                'owner' => $owner,
                'acquired_at' => $now,
                'expires_at' => $now->modify("+{$leaseSeconds} seconds"),
            ];

            if ($row instanceof stdClass) {
                $query->update($values);
            } else {
                $this->connection->table('aleph_continuation_leases')->insert($values);
            }

            return $this->find((string) $values['id']) ?? throw new ResumeRejected('lease_failed', 'The continuation lease could not be read back.');
        });
    }

    public function find(string $id): ?ContinuationLease
    {
        $row = $this->connection->table('aleph_continuation_leases')->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function hydrate(stdClass $row): ContinuationLease
    {
        return new ContinuationLease(
            id: (string) $row->id,
            sourceStreamId: (string) $row->source_stream_id,
            capability: Capability::from((string) $row->capability),
            partitionKey: (string) $row->partition_key,
            runId: (string) $row->run_id,
            owner: (string) $row->owner,
            acquiredAt: new DateTimeImmutable((string) $row->acquired_at),
            expiresAt: new DateTimeImmutable((string) $row->expires_at),
        );
    }
}
