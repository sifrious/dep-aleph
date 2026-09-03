<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\VideoFile;

final readonly class LocalVideoFilePayload
{
    public function __construct(
        public string $name,
        public string $contents,
        public ?string $mediaType = null,
    ) {}
}
