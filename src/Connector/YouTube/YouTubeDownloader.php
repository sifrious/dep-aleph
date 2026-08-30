<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

interface YouTubeDownloader
{
    public function download(YouTubeCanonicalUrl $url): YouTubeDownload;
}
