<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class IngestionContinuation
{
    public function __construct(
        public string $partitionKey,
        public ?IngestionCheckpoint $checkpoint,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'partition_key' => $this->partitionKey,
            'checkpoint_id' => $this->checkpoint?->id,
            'checkpoint_version' => $this->checkpoint?->version,
            'format' => $this->checkpoint?->value->format,
            'serializer_version' => $this->checkpoint?->value->serializerVersion,
            'value' => $this->checkpoint?->value->value,
            'accepted_references' => $this->checkpoint->acceptedReferences ?? [],
        ];
    }
}
