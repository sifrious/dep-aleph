<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Tests\Fixtures;

/**
 * Tiny fixture image bytes for package tests (no GD required to construct).
 */
final class ImageFixture
{
    /**
     * 1×1 opaque red PNG (RGB).
     */
    public static function png1x1(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
            true,
        ) ?: '';
    }

    /**
     * Minimal JPEG with no EXIF APP1 segment (SOF0 1×1 grayscale).
     */
    public static function jpegWithoutExif(): string
    {
        return hex2bin(
            'ffd8ffe000104a46494600010100000100010000'
            .'ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432'
            .'ffc0000b080001000101011100'
            .'ffc40014100100000000000000000000000000000000'
            .'ffda0008010100003f00bf'
            .'ffd9'
        ) ?: '';
    }

    /**
     * Minimal JPEG whose APP1 Exif segment exists but primary IFD entry count is zero.
     */
    public static function jpegEmptyExif(): string
    {
        // SOI + APP1 Exif (empty IFD) + SOF0 1×1 + EOI
        $tiff = 'II*\x00'           // little-endian TIFF header
            ."\x08\x00\x00\x00"     // IFD0 offset
            ."\x00\x00"             // entry count = 0
            ."\x00\x00\x00\x00";    // next IFD
        $exifPayload = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exifPayload) + 2).$exifPayload;
        $sof = "\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00";
        $eoi = "\xFF\xD9";

        return "\xFF\xD8".$app1.$sof.$eoi;
    }

    /**
     * Minimal JPEG with a Present EXIF DateTimeOriginal ASCII tag.
     */
    public static function jpegWithExifDate(): string
    {
        $date = "2026:08:29 12:00:00\0"; // 20 bytes
        // Build TIFF with one ASCII tag (0x9003 DateTimeOriginal) stored inline? count=20 > 4, so offset.
        // Layout:
        // 0: II*\0
        // 4: IFD offset = 8
        // 8: count = 1
        // 10: tag entry (12 bytes)
        // 22: next IFD = 0
        // 26: date bytes
        $ifdOffset = 8;
        $valueOffset = 26;
        $entry = pack('v', 0x9003)          // tag
            .pack('v', 2)                   // ASCII
            .pack('V', strlen($date))       // count
            .pack('V', $valueOffset);       // offset to value
        $tiff = 'II*\x00'
            .pack('V', $ifdOffset)
            .pack('v', 1)
            .$entry
            ."\x00\x00\x00\x00"
            .$date;
        $exifPayload = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exifPayload) + 2).$exifPayload;
        $sof = "\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00";

        return "\xFF\xD8".$app1.$sof."\xFF\xD9";
    }
}
