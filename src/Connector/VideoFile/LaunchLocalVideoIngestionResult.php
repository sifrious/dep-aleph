<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

final readonly class LaunchLocalVideoIngestionResult
{
    /**
     * @param  list<string>  $acceptedReferences
     */
    public function __construct(
        public string $runId,
        public bool $replayed,
        public array $acceptedReferences,
    ) {}
}
