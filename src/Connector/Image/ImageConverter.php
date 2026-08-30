<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

interface ImageConverter
{
    /**
     * Convert image bytes to the requested output format while preserving pixel dimensions.
     */
    public function convert(string $contents, string $sourceMediaType, string $targetFormat): ImageConversion;
}
