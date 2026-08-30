<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

use InvalidArgumentException;

/**
 * Explicit same-pixels format conversion via PHP GD.
 * HEIC and other formats GD cannot decode remain unsupported here; hosts may bind another converter.
 */
final class GdImageConverter implements ImageConverter
{
    public function convert(string $contents, string $sourceMediaType, string $targetFormat): ImageConversion
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagecreatetruecolor')) {
            throw new InvalidArgumentException('Image conversion requires the PHP GD extension.');
        }

        $format = strtolower(trim($targetFormat));
        $targetMediaType = $this->mediaTypeFor($format);

        if ($targetMediaType === null) {
            throw new InvalidArgumentException("Unsupported image conversion target format [{$targetFormat}].");
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw new InvalidArgumentException('Image conversion could not decode the source image bytes.');
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width < 1 || $height < 1) {
                throw new InvalidArgumentException('Image conversion requires a positive pixel grid.');
            }

            ob_start();
            $ok = match ($format) {
                'jpg', 'jpeg' => imagejpeg($image, null, 90),
                'png' => imagepng($image),
                'gif' => imagegif($image),
                'webp' => function_exists('imagewebp') ? imagewebp($image) : false,
                default => false,
            };
            $converted = ob_get_clean();

            if ($ok !== true || ! is_string($converted) || $converted === '') {
                throw new InvalidArgumentException("Image conversion to [{$format}] failed.");
            }

            $checksum = hash('sha256', $converted);

            return new ImageConversion(
                converterName: ImageConversion::CONVERTER_NAME,
                converterVersion: ImageConversion::CONVERTER_VERSION,
                sourceMediaType: $sourceMediaType,
                targetMediaType: $targetMediaType,
                targetFormat: $format === 'jpg' ? 'jpeg' : $format,
                contents: $converted,
                checksum: $checksum,
                bytes: strlen($converted),
                width: $width,
                height: $height,
            );
        } finally {
            imagedestroy($image);
        }
    }

    private function mediaTypeFor(string $format): ?string
    {
        return match ($format) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => null,
        };
    }
}
