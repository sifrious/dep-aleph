<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

use InvalidArgumentException;

/**
 * Result of an explicit same-pixels format conversion.
 * Stored as a Funes extracted-representation version (MME-1438); never overwrites raw bytes.
 */
final readonly class ImageConversion
{
    public const CONVERTER_NAME = 'aleph.gd';

    public const CONVERTER_VERSION = '1.0.0';

    public function __construct(
        public string $converterName,
        public string $converterVersion,
        public string $sourceMediaType,
        public string $targetMediaType,
        public string $targetFormat,
        public string $contents,
        public string $checksum,
        public int $bytes,
        public ?int $width,
        public ?int $height,
    ) {
        if (trim($converterName) === '' || trim($converterVersion) === '') {
            throw new InvalidArgumentException('An image conversion requires converter name and version.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toExtractionResult(string $sourceChecksum): array
    {
        return [
            'kind' => 'image_format_conversion',
            'converter' => [
                'name' => $this->converterName,
                'version' => $this->converterVersion,
            ],
            'source_media_type' => $this->sourceMediaType,
            'target_media_type' => $this->targetMediaType,
            'target_format' => $this->targetFormat,
            'source_sha256' => $sourceChecksum,
            'converted_sha256' => $this->checksum,
            'bytes' => $this->bytes,
            'width' => $this->width,
            'height' => $this->height,
            // Same pixels, new container. Raw observation payload remains authoritative.
            'contents_base64' => base64_encode($this->contents),
        ];
    }
}
