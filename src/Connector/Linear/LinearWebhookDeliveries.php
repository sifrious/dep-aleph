<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use DateTimeImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final readonly class LinearWebhookDeliveries
{
    public function __construct(
        private ConnectionInterface $connection,
        private Encrypter $encrypter,
    ) {}

    public function persist(
        string $sourceInstallationId,
        string $deliveryId,
        string $event,
        string $payload,
        ?DateTimeImmutable $receivedAt = null,
    ): LinearWebhookRecord {
        if (trim($deliveryId) === '' || trim($event) === '') {
            throw new InvalidArgumentException('A Linear webhook requires delivery and event identifiers.');
        }

        $existing = $this->find($sourceInstallationId, $deliveryId);

        if ($existing !== null) {
            if (! hash_equals(hash('sha256', $existing->payload), hash('sha256', $payload))) {
                throw new InvalidArgumentException('A Linear delivery ID cannot be reused with a different payload.');
            }

            return $existing;
        }

        $id = (string) Str::ulid();
        $receivedAt ??= new DateTimeImmutable;
        $this->connection->table('aleph_linear_webhook_deliveries')->insert([
            'id' => $id,
            'source_installation_id' => $sourceInstallationId,
            'delivery_id' => $deliveryId,
            'event' => $event,
            'payload_hash' => hash('sha256', $payload),
            'payload' => $this->encrypter->encrypt($payload),
            'accepted_references' => null,
            'received_at' => $receivedAt,
            'processed_at' => null,
        ]);

        return $this->find($sourceInstallationId, $deliveryId)
            ?? throw new InvalidArgumentException('The Linear webhook delivery could not be persisted.');
    }

    /** @param list<string> $acceptedReferences */
    public function markProcessed(LinearWebhookRecord $record, array $acceptedReferences): LinearWebhookRecord
    {
        $this->connection->table('aleph_linear_webhook_deliveries')->where('id', $record->id)->update([
            'accepted_references' => json_encode(array_values(array_unique($acceptedReferences)), JSON_THROW_ON_ERROR),
            'processed_at' => new DateTimeImmutable,
        ]);

        return $this->find($record->sourceInstallationId, $record->deliveryId) ?? $record;
    }

    public function find(string $sourceInstallationId, string $deliveryId): ?LinearWebhookRecord
    {
        $row = $this->connection->table('aleph_linear_webhook_deliveries')
            ->where('source_installation_id', $sourceInstallationId)
            ->where('delivery_id', $deliveryId)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function hydrate(stdClass $row): LinearWebhookRecord
    {
        $accepted = $row->accepted_references === null
            ? []
            : json_decode((string) $row->accepted_references, true, 512, JSON_THROW_ON_ERROR);

        return new LinearWebhookRecord(
            (string) $row->id,
            (string) $row->source_installation_id,
            (string) $row->delivery_id,
            (string) $row->event,
            (string) $this->encrypter->decrypt((string) $row->payload),
            is_array($accepted) ? array_values(array_map(strval(...), $accepted)) : [],
            new DateTimeImmutable((string) $row->received_at),
            $row->processed_at === null ? null : new DateTimeImmutable((string) $row->processed_at),
        );
    }
}
