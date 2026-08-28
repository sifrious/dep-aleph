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
        public bool $enabled,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
