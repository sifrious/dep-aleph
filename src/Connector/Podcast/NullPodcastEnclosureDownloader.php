<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

final class NullPodcastEnclosureDownloader implements PodcastEnclosureDownloader
{
    public function download(string $enclosureUrl): PodcastEnclosureDownload
    {
        throw new UnfetchablePodcastEpisode(
            'No podcast enclosure downloader is configured. Register a host adapter that can fetch podcast enclosure URLs.',
        );
    }
}
