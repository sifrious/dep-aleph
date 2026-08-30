<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

use InvalidArgumentException;

final readonly class ConvertImageFormatRequest
{
    public function __construct(
        public string $observationId,
        public string $runId,
        public string $sourceContents,
        public string $sourceMediaType,
        public string $targetFormat,
    ) {
        if (trim($observationId) === '' || trim($runId) === '') {
            throw new InvalidArgumentException('Image conversion requires observation and run identifiers.');
        }

        if ($sourceContents === '') {
            throw new InvalidArgumentException('Image conversion requires the authoritative source image bytes.');
        }

        if (trim($targetFormat) === '') {
            throw new InvalidArgumentException('Image conversion requires a target output format.');
        }
    }
}
