<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

final readonly class LocalScoreTabFilePayload
{
    public function __construct(
        public string $name,
        public string $contents,
        public ?string $mediaType = null,
    ) {}
}
