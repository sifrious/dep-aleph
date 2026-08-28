<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use InvalidArgumentException;

final readonly class LinearAttachmentReference
{
    public function __construct(
        public string $providerId,
        public string $url,
        public ?string $title = null,
        public ?string $sourceType = null,
    ) {
        if (trim($providerId) === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Linear attachment references require a provider ID and valid URL.');
        }
    }

    /** @return array<string, ?string> */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'url' => $this->url,
            'title' => $this->title,
            'source_type' => $this->sourceType,
        ];
    }
}
