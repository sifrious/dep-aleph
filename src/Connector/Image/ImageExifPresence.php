<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

/**
 * Missing EXIF (no segment/chunk) is distinct from an empty EXIF payload.
 */
enum ImageExifPresence: string
{
    case Missing = 'missing';
    case Empty = 'empty';
    case Present = 'present';
}
