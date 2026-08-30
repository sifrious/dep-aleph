<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Capture bytes with a capture timestamp only.
 * Screenshot session/window metadata remains MME-827 and is not accepted here.
 */
final readonly class ImageCapturePayload
{
    public function __construct(
        public string $name,
        public string $contents,
        public DateTimeImmutable $capturedAt,
        public ?string $mediaType = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Image capture payload requires a stable file name.');
        }
    }
}
