<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class SourceStream
{
    public function __construct(
        public string $id,
        public string $sourceInstallationId,
        public string $key,
        public ?string $scopeType,
        public ?string $scopeId,
        public SyncStrategy $syncStrategy,
        public bool $enabled,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_installation_id' => $this->sourceInstallationId,
            'key' => $this->key,
            'scope_type' => $this->scopeType,
            'scope_id' => $this->scopeId,
            'sync_strategy' => $this->syncStrategy->value,
            'enabled' => $this->enabled,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
