<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

final readonly class PodcastEpisodeResolution
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $episodeIdentity,
        public string $enclosureUrl,
        public array $metadata = [],
    ) {}
}
