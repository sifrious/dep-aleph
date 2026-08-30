<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

final readonly class LaunchPodcastIngestionResult
{
    /**
     * @param  list<string>  $acceptedReferences
     */
    public function __construct(
        public string $runId,
        public bool $replayed,
        public string $episodeIdentity,
        public string $enclosureUrl,
        public array $acceptedReferences,
    ) {}
}
