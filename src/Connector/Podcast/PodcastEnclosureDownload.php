<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Podcast;

final readonly class PodcastEnclosureDownload
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $mediaType,
        public string $contents,
        public array $metadata = [],
    ) {}
}
