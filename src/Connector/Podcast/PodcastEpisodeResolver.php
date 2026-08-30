<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

interface PodcastEpisodeResolver
{
    public function resolve(string $reference): PodcastEpisodeResolution;
}
