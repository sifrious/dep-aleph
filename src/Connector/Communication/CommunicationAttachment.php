<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use InvalidArgumentException;

final readonly class CommunicationAttachment
{
    public function __construct(
        public string $providerId,
        public string $digoryReference,
        public ?string $filename = null,
        public ?string $mediaType = null,
        public ?int $size = null,
        public ?string $kind = null,
    ) {
        if (trim($providerId) === '' || ! str_starts_with($digoryReference, 'digory:')) {
            throw new InvalidArgumentException('Communication attachments require provider and Digory references.');
        }
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'digory_reference' => $this->digoryReference,
            'filename' => $this->filename,
            'media_type' => $this->mediaType,
            'size' => $this->size,
            'kind' => $this->kind,
        ];
    }
}
