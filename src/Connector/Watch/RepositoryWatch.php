<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Watch;

use DateTimeImmutable;

final readonly class RepositoryWatch
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $checkpoint
     */
    public function __construct(
        public string $id,
        public ?string $legacyReference,
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

    public function status(DateTimeImmutable $at): RepositoryWatchStatus
    {
        if (! $this->enabled) {
            return RepositoryWatchStatus::Disabled;
        }

        if ($this->backoffUntil !== null && $this->backoffUntil > $at) {
            return RepositoryWatchStatus::Backoff;
        }

        if ($this->lastError !== null) {
            return RepositoryWatchStatus::Error;
        }

        if ($this->nextSyncAt === null || $this->nextSyncAt <= $at) {
            return RepositoryWatchStatus::Due;
        }

        return RepositoryWatchStatus::Scheduled;
    }
}
