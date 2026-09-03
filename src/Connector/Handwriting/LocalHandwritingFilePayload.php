<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Handwriting;

final readonly class LocalHandwritingFilePayload
{
    public function __construct(
        public string $name,
        public string $contents,
        public ?string $mediaType = null,
    ) {}
}
