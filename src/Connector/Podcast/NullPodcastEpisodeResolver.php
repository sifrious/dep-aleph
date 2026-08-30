<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

final class NullPodcastEpisodeResolver implements PodcastEpisodeResolver
{
    public function resolve(string $reference): PodcastEpisodeResolution
    {
        throw new UnsupportedPodcastReference(
            'No podcast episode resolver is configured. Register a host adapter for Apple Podcasts, Apple Music podcast episodes, or RSS sources.',
        );
    }
}
