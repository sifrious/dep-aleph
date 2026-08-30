<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

final readonly class LocalMidiFilePayload
{
    public function __construct(
        public string $name,
        public string $contents,
        public ?string $mediaType = null,
    ) {}
}
