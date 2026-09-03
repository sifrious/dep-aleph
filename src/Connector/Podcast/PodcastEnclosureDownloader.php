<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

interface PodcastEnclosureDownloader
{
    public function download(string $enclosureUrl): PodcastEnclosureDownload;
}
