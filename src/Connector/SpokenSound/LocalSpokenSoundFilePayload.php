<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\SpokenSound;

final readonly class LocalSpokenSoundFilePayload
{
    public function __construct(
        public string $name,
        public string $contents,
        public ?string $mediaType = null,
    ) {}
}
