<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

/**
 * Lightweight mechanical inspection of image bytes.
 * Does not classify content. Distinguishes missing vs empty EXIF.
 */
final class BinaryImageMetadataInspector implements ImageMetadataInspector
{
    public function inspect(string $contents, string $mediaType, array $hints = []): ImageMetadata
    {
        $dimensions = $this->dimensions($contents, $mediaType);
        $exif = $this->exif($contents, $mediaType);
        $capturedAt = is_string($hints['captured_at'] ?? null) ? $hints['captured_at'] : null;
        $modifiedAt = is_string($hints['modified_at'] ?? null) ? $hints['modified_at'] : null;

        if ($capturedAt === null && isset($exif['fields']['DateTimeOriginal']) && is_string($exif['fields']['DateTimeOriginal'])) {
            $capturedAt = $exif['fields']['DateTimeOriginal'];
        }

        return new ImageMetadata(
            width: $dimensions['width'],
            height: $dimensions['height'],
            colorSpace: $dimensions['color_space'],
            capturedAt: $capturedAt,
            modifiedAt: $modifiedAt,
            exifPresence: $exif['presence'],
            exifFields: $exif['fields'],
        );
    }

    /**
     * @return array{width: ?int, height: ?int, color_space: ?string}
     */
    private function dimensions(string $contents, string $mediaType): array
    {
        if (str_starts_with($contents, "\x89PNG\r\n\x1a\n") && strlen($contents) >= 24) {
            $width = unpack('N', substr($contents, 16, 4))[1] ?? null;
            $height = unpack('N', substr($contents, 20, 4))[1] ?? null;
            $colorType = ord($contents[25] ?? "\x00");
            $colorSpace = match ($colorType) {
                0 => 'gray',
                2 => 'rgb',
                3 => 'indexed',
                4 => 'gray-alpha',
                6 => 'rgba',
                default => null,
            };

            return [
                'width' => is_int($width) ? $width : null,
                'height' => is_int($height) ? $height : null,
                'color_space' => $colorSpace,
            ];
        }

        if (str_starts_with($mediaType, 'image/jpeg') || str_starts_with($contents, "\xFF\xD8")) {
            return $this->jpegDimensions($contents);
        }

        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($contents);

            if (is_array($info)) {
                return [
                    'width' => $info[0],
                    'height' => $info[1],
                    'color_space' => null,
                ];
            }
        }

        return ['width' => null, 'height' => null, 'color_space' => null];
    }

    /**
     * @return array{width: ?int, height: ?int, color_space: ?string}
     */
    private function jpegDimensions(string $contents): array
    {
        $length = strlen($contents);
        $offset = 2;

        while ($offset + 9 < $length) {
            if ($contents[$offset] !== "\xFF") {
                break;
            }

            $marker = ord($contents[$offset + 1]);
            $offset += 2;

            if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }

            if ($offset + 2 > $length) {
                break;
            }

            $segmentLength = unpack('n', substr($contents, $offset, 2))[1] ?? 0;
            $offset += 2;

            if ($segmentLength < 2 || $offset + $segmentLength - 2 > $length) {
                break;
            }

            // SOF0..SOF3, SOF5..SOF7, SOF9..SOF11, SOF13..SOF15
            if (($marker >= 0xC0 && $marker <= 0xC3)
                || ($marker >= 0xC5 && $marker <= 0xC7)
                || ($marker >= 0xC9 && $marker <= 0xCB)
                || ($marker >= 0xCD && $marker <= 0xCF)
            ) {
                $height = unpack('n', substr($contents, $offset + 1, 2))[1] ?? null;
                $width = unpack('n', substr($contents, $offset + 3, 2))[1] ?? null;
                $components = ord($contents[$offset + 5] ?? "\x00");

                return [
                    'width' => is_int($width) ? $width : null,
                    'height' => is_int($height) ? $height : null,
                    'color_space' => match ($components) {
                        1 => 'gray',
                        3 => 'ycbcr',
                        4 => 'cmyk',
                        default => null,
                    },
                ];
            }

            $offset += $segmentLength - 2;
        }

        return ['width' => null, 'height' => null, 'color_space' => null];
    }

    /**
     * @return array{presence: ImageExifPresence, fields: array<string, mixed>}
     */
    private function exif(string $contents, string $mediaType): array
    {
        if (str_starts_with($contents, "\x89PNG\r\n\x1a\n")) {
            return $this->pngExif($contents);
        }

        if (str_starts_with($mediaType, 'image/jpeg') || str_starts_with($contents, "\xFF\xD8")) {
            return $this->jpegExif($contents);
        }

        return ['presence' => ImageExifPresence::Missing, 'fields' => []];
    }

    /**
     * @return array{presence: ImageExifPresence, fields: array<string, mixed>}
     */
    private function pngExif(string $contents): array
    {
        $length = strlen($contents);
        $offset = 8;

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($contents, $offset, 4))[1] ?? 0;
            $type = substr($contents, $offset + 4, 4);
            $offset += 8;

            if ($chunkLength < 0 || $offset + $chunkLength + 4 > $length) {
                break;
            }

            if ($type === 'eXIf') {
                $payload = substr($contents, $offset, $chunkLength);

                if ($payload === '' || $this->tiffIfdEmpty($payload)) {
                    return ['presence' => ImageExifPresence::Empty, 'fields' => []];
                }

                return [
                    'presence' => ImageExifPresence::Present,
                    'fields' => $this->readPrimaryTiffAsciiTags($payload),
                ];
            }

            if ($type === 'IEND') {
                break;
            }

            $offset += $chunkLength + 4;
        }

        return ['presence' => ImageExifPresence::Missing, 'fields' => []];
    }

    /**
     * @return array{presence: ImageExifPresence, fields: array<string, mixed>}
     */
    private function jpegExif(string $contents): array
    {
        $length = strlen($contents);
        $offset = 2;

        while ($offset + 4 < $length) {
            if ($contents[$offset] !== "\xFF") {
                break;
            }

            $marker = ord($contents[$offset + 1]);
            $offset += 2;

            if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }

            if ($offset + 2 > $length) {
                break;
            }

            $segmentLength = unpack('n', substr($contents, $offset, 2))[1] ?? 0;
            $offset += 2;

            if ($segmentLength < 2 || $offset + $segmentLength - 2 > $length) {
                break;
            }

            $payload = substr($contents, $offset, $segmentLength - 2);

            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
                $tiff = substr($payload, 6);

                if ($tiff === '' || $this->tiffIfdEmpty($tiff)) {
                    return ['presence' => ImageExifPresence::Empty, 'fields' => []];
                }

                return [
                    'presence' => ImageExifPresence::Present,
                    'fields' => $this->readPrimaryTiffAsciiTags($tiff),
                ];
            }

            // SOS — image data begins; stop scanning.
            if ($marker === 0xDA) {
                break;
            }

            $offset += $segmentLength - 2;
        }

        return ['presence' => ImageExifPresence::Missing, 'fields' => []];
    }

    private function tiffIfdEmpty(string $tiff): bool
    {
        if (strlen($tiff) < 8) {
            return true;
        }

        $endian = substr($tiff, 0, 2);
        $little = $endian === 'II';

        if (! $little && $endian !== 'MM') {
            return true;
        }

        $ifdOffset = $this->readUint32($tiff, 4, $little);

        if ($ifdOffset + 2 > strlen($tiff)) {
            return true;
        }

        $entryCount = $this->readUint16($tiff, $ifdOffset, $little);

        return $entryCount === 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function readPrimaryTiffAsciiTags(string $tiff): array
    {
        if (strlen($tiff) < 8) {
            return [];
        }

        $endian = substr($tiff, 0, 2);
        $little = $endian === 'II';

        if (! $little && $endian !== 'MM') {
            return [];
        }

        $ifdOffset = $this->readUint32($tiff, 4, $little);

        if ($ifdOffset + 2 > strlen($tiff)) {
            return [];
        }

        $entryCount = $this->readUint16($tiff, $ifdOffset, $little);
        $fields = [];
        $tagNames = [
            0x010F => 'Make',
            0x0110 => 'Model',
            0x0132 => 'DateTime',
            0x9003 => 'DateTimeOriginal',
        ];

        for ($i = 0; $i < $entryCount; $i++) {
            $entryOffset = $ifdOffset + 2 + ($i * 12);

            if ($entryOffset + 12 > strlen($tiff)) {
                break;
            }

            $tag = $this->readUint16($tiff, $entryOffset, $little);
            $type = $this->readUint16($tiff, $entryOffset + 2, $little);
            $count = $this->readUint32($tiff, $entryOffset + 4, $little);

            if ($type !== 2 || $count < 1 || ! isset($tagNames[$tag])) {
                continue;
            }

            $valueOffset = $entryOffset + 8;

            if ($count > 4) {
                $valueOffset = $this->readUint32($tiff, $entryOffset + 8, $little);
            }

            if ($valueOffset + $count > strlen($tiff)) {
                continue;
            }

            $raw = substr($tiff, $valueOffset, $count);
            $fields[$tagNames[$tag]] = rtrim($raw, "\0");
        }

        return $fields;
    }

    private function readUint16(string $bytes, int $offset, bool $little): int
    {
        $chunk = substr($bytes, $offset, 2);
        $parts = unpack($little ? 'v' : 'n', $chunk);

        return (int) ($parts[1] ?? 0);
    }

    private function readUint32(string $bytes, int $offset, bool $little): int
    {
        $chunk = substr($bytes, $offset, 4);
        $parts = unpack($little ? 'V' : 'N', $chunk);

        return (int) ($parts[1] ?? 0);
    }
}
