<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

final readonly class LocalImageFilePayload
{
    public function __construct(
        public string $name,
        public string $contents,
        public ?string $mediaType = null,
    ) {}
}
