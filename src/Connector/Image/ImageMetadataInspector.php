<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

interface ImageMetadataInspector
{
    /**
     * @param  array{
     *     input?: string,
     *     path?: string,
     *     captured_at?: string,
     *     modified_at?: string
     * }  $hints
     */
    public function inspect(string $contents, string $mediaType, array $hints = []): ImageMetadata;
}
