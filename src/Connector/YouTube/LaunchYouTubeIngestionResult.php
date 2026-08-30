<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

final readonly class LaunchYouTubeIngestionResult
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
