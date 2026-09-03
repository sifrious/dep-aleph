<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

final readonly class YouTubeDownload
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $mediaType,
        public string $contents,
        public array $metadata = [],
        public ?YouTubeTranscript $transcript = null,
    ) {}
}
