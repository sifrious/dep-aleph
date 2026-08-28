<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final readonly class IngestionPartitions
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @param  list<string>  $keys
     * @return list<IngestionPartition>
     */
    public function create(IngestionRun $run, array $keys, DateTimeImmutable $at): array
    {
        $keys = array_values(array_unique(array_filter(
            array_map(trim(...), $keys),
            static fn (string $key): bool => $key !== '',
        )));
        sort($keys, SORT_STRING);

        if ($keys === []) {
            throw new InvalidArgumentException('A backfill requires at least one stable partition.');
        }

        if ($this->forRun($run) !== []) {
            return $this->forRun($run);
        }

        foreach ($keys as $position => $key) {
            $this->table()->insert([
                'id' => (string) Str::ulid(),
                'run_id' => $run->id,
                'key' => $key,
                'position' => $position,
                'status' => PartitionStatus::Pending->value,
                'checkpoint' => null,
                'processed' => 0,
                'accepted' => 0,
                'failed' => 0,
                'failure' => null,
                'started_at' => null,
                'finished_at' => null,
                'updated_at' => $at,
            ]);
        }

        return $this->forRun($run);
    }

    /**
     * @return list<IngestionPartition>
     */
    public function forRun(IngestionRun $run): array
    {
        return array_values($this->table()
            ->where('run_id', $run->id)
            ->orderBy('position')
            ->get()
            ->map(fn (stdClass $row): IngestionPartition => $this->hydrate($row))
            ->all());
    }

    public function begin(IngestionPartition $partition, DateTimeImmutable $at): IngestionPartition
    {
        $current = $this->find($partition->id);

        if ($current === null || ! in_array($current->status, [PartitionStatus::Pending, PartitionStatus::Paused, PartitionStatus::Failed], true)) {
            throw new InvalidArgumentException('Only pending, paused, or failed partitions can begin.');
        }

        $this->table()->where('id', $current->id)->update([
            'status' => PartitionStatus::Running->value,
            'failure' => null,
            'started_at' => $current->startedAt ?? $at,
            'finished_at' => null,
            'updated_at' => $at,
        ]);

        return $this->find($current->id) ?? $current;
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    public function progress(
        IngestionPartition $partition,
        array $checkpoint,
        int $processed,
        int $accepted,
        int $failed,
        DateTimeImmutable $at,
    ): IngestionPartition {
        $current = $this->running($partition);

        if ($processed < $current->processed
            || $accepted < $current->accepted
            || $failed < $current->failed
            || $accepted + $failed > $processed
        ) {
            throw new InvalidArgumentException('Partition progress must be cumulative and internally consistent.');
        }

        $this->table()->where('id', $current->id)->update([
            'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
            'processed' => $processed,
            'accepted' => $accepted,
            'failed' => $failed,
            'updated_at' => $at,
        ]);

        return $this->find($current->id) ?? $current;
    }

    public function pause(IngestionPartition $partition, DateTimeImmutable $at): IngestionPartition
    {
        return $this->finishState($this->running($partition), PartitionStatus::Paused, null, $at, false);
    }

    public function fail(IngestionPartition $partition, string $failure, DateTimeImmutable $at): IngestionPartition
    {
        if (trim($failure) === '') {
            throw new InvalidArgumentException('A failed partition requires an explanation.');
        }

        return $this->finishState($this->running($partition), PartitionStatus::Failed, $failure, $at, true);
    }

    public function complete(IngestionPartition $partition, DateTimeImmutable $at): IngestionPartition
    {
        return $this->finishState($this->running($partition), PartitionStatus::Completed, null, $at, true);
    }

    public function allComplete(IngestionRun $run): bool
    {
        return $this->table()->where('run_id', $run->id)->exists()
            && ! $this->table()->where('run_id', $run->id)->where('status', '!=', PartitionStatus::Completed->value)->exists();
    }

    private function running(IngestionPartition $partition): IngestionPartition
    {
        $current = $this->find($partition->id);

        if ($current === null || $current->status !== PartitionStatus::Running) {
            throw new InvalidArgumentException('Partition progress requires a running partition.');
        }

        return $current;
    }

    private function finishState(
        IngestionPartition $partition,
        PartitionStatus $status,
        ?string $failure,
        DateTimeImmutable $at,
        bool $finished,
    ): IngestionPartition {
        $this->table()->where('id', $partition->id)->update([
            'status' => $status->value,
            'failure' => $failure,
            'finished_at' => $finished ? $at : null,
            'updated_at' => $at,
        ]);

        return $this->find($partition->id) ?? $partition;
    }

    private function find(string $id): ?IngestionPartition
    {
        $row = $this->table()->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function hydrate(stdClass $row): IngestionPartition
    {
        $checkpoint = $row->checkpoint === null ? [] : json_decode((string) $row->checkpoint, true, 512, JSON_THROW_ON_ERROR);

        return new IngestionPartition(
            id: (string) $row->id,
            runId: (string) $row->run_id,
            key: (string) $row->key,
            position: (int) $row->position,
            status: PartitionStatus::from((string) $row->status),
            checkpoint: is_array($checkpoint) ? $checkpoint : [],
            processed: (int) $row->processed,
            accepted: (int) $row->accepted,
            failed: (int) $row->failed,
            failure: $row->failure === null ? null : (string) $row->failure,
            startedAt: $row->started_at === null ? null : new DateTimeImmutable((string) $row->started_at),
            finishedAt: $row->finished_at === null ? null : new DateTimeImmutable((string) $row->finished_at),
            updatedAt: new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_ingestion_partitions');
    }
}
