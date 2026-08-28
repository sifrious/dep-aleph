<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final readonly class SourceStreams
{
    public function __construct(private ConnectionInterface $connection) {}

    public function create(
        string $sourceInstallationId,
        string $key,
        ?string $scopeType = null,
        ?string $scopeId = null,
    ): SourceStream {
        if (trim($sourceInstallationId) === '' || trim($key) === '') {
            throw new InvalidArgumentException('A source stream requires an installation and stable key.');
        }

        $existing = $this->findByKey($sourceInstallationId, $key);

        if ($existing !== null) {
            return $existing;
        }

        $now = new DateTimeImmutable;
        $id = (string) Str::ulid();
        $this->connection->table('aleph_source_streams')->insert([
            'id' => $id,
            'source_installation_id' => $sourceInstallationId,
            'stream_key' => $key,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($id) ?? throw new InvalidArgumentException('The source stream could not be created.');
    }

    public function find(string $id): ?SourceStream
    {
        $row = $this->connection->table('aleph_source_streams')->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function findByKey(string $sourceInstallationId, string $key): ?SourceStream
    {
        $row = $this->connection->table('aleph_source_streams')
            ->where('source_installation_id', $sourceInstallationId)
            ->where('stream_key', $key)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * @return list<SourceStream>
     */
    public function active(string $sourceInstallationId): array
    {
        return array_values($this->connection->table('aleph_source_streams')
            ->where('source_installation_id', $sourceInstallationId)
            ->where('enabled', true)
            ->orderBy('stream_key')
            ->get()
            ->map(fn (stdClass $row): SourceStream => $this->hydrate($row))
            ->all());
    }

    public function disable(SourceStream $stream): void
    {
        $this->connection->table('aleph_source_streams')->where('id', $stream->id)->update([
            'enabled' => false,
            'updated_at' => new DateTimeImmutable,
        ]);
    }

    private function hydrate(stdClass $row): SourceStream
    {
        return new SourceStream(
            (string) $row->id,
            (string) $row->source_installation_id,
            (string) $row->stream_key,
            $row->scope_type === null ? null : (string) $row->scope_type,
            $row->scope_id === null ? null : (string) $row->scope_id,
            (bool) $row->enabled,
            new DateTimeImmutable((string) $row->created_at),
            new DateTimeImmutable((string) $row->updated_at),
        );
    }
}
