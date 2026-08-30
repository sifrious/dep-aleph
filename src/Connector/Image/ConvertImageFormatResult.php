<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

final readonly class ConvertImageFormatResult
{
    public function __construct(
        public string $observationId,
        public ImageConversion $conversion,
    ) {}
}
