<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\YouTube;

final readonly class YouTubeTranscript
{
    public function __construct(
        public string $mediaType,
        public string $contents,
        public ?string $language = null,
    ) {}
}
