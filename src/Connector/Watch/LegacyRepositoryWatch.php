<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Watch;

use DateTimeImmutable;

final readonly class LegacyRepositoryWatch
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $checkpoint
     */
    public function __construct(
        public string $legacyReference,
        public string $sourceInstallationId,
        public string $sourceReference,
        public string $repositoryReference,
        public RepositoryWatchMode $mode,
        public array $filters,
        public int $cadenceSeconds,
        public array $checkpoint,
        public ?string $headReference,
        public bool $enabled,
        public ?DateTimeImmutable $lastSyncedAt,
        public ?DateTimeImmutable $backfillCompletedAt,
        public ?DateTimeImmutable $nextSyncAt,
        public ?string $lastError,
        public ?DateTimeImmutable $backoffUntil,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
