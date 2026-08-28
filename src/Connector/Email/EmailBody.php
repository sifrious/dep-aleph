<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

final readonly class EmailBody
{
    public function __construct(
        public string $mediaType,
        public string $content,
        public ?string $contentId = null,
    ) {}

    /** @return array<string, ?string> */
    public function toArray(): array
    {
        return ['media_type' => $this->mediaType, 'content' => $this->content, 'content_id' => $this->contentId];
    }
}
