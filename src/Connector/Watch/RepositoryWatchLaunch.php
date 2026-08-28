<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Watch;

use Sifrious\Aleph\Ingestion\IncrementalSyncResult;

final readonly class RepositoryWatchLaunch
{
    public function __construct(
        public RepositoryWatch $watch,
        public RepositoryWatchSignal $signal,
        public ?IncrementalSyncResult $ingestion,
        public bool $coalesced,
    ) {}

    public function launched(): bool
    {
        return $this->ingestion !== null && ! $this->coalesced;
    }
}
