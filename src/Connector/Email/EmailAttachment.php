<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use InvalidArgumentException;

final readonly class EmailAttachment
{
    public function __construct(
        public string $providerId,
        public ?string $filename,
        public ?string $mediaType,
        public ?int $byteSize,
        public string $historicalReference,
    ) {
        if (trim($providerId) === '' || trim($historicalReference) === '' || ($byteSize !== null && $byteSize < 0)) {
            throw new InvalidArgumentException('Email attachments require stable provider and historical references.');
        }
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'filename' => $this->filename,
            'media_type' => $this->mediaType,
            'byte_size' => $this->byteSize,
            'historical_reference' => $this->historicalReference,
        ];
    }
}
