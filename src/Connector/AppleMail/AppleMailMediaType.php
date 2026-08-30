<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\AppleMail;

/**
 * Computes attachment media types from raw bytes. Declared types and filenames are
 * fallbacks only — raw bytes stay authoritative for type detection.
 */
final class AppleMailMediaType
{
    public static function fromBytes(string $contents, ?string $filename = null, ?string $declared = null): string
    {
        $detected = self::detectFromBytes($contents);

        if ($detected !== null) {
            return $detected;
        }

        if ($filename !== null && trim($filename) !== '') {
            $fromName = self::fromExtension($filename);

            if ($fromName !== 'application/octet-stream') {
                return $fromName;
            }
        }

        if ($declared !== null && trim($declared) !== '') {
            return strtolower(trim($declared));
        }

        return 'application/octet-stream';
    }

    private static function detectFromBytes(string $contents): ?string
    {
        if ($contents === '') {
            return null;
        }

        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($contents);

            if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
                return strtolower($detected);
            }
        }

        return self::magicBytes($contents);
    }

    private static function magicBytes(string $contents): ?string
    {
        $prefix = substr($contents, 0, 16);

        return match (true) {
            str_starts_with($prefix, "%PDF") => 'application/pdf',
            str_starts_with($prefix, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($prefix, "\xff\xd8\xff") => 'image/jpeg',
            str_starts_with($prefix, 'GIF87a'), str_starts_with($prefix, 'GIF89a') => 'image/gif',
            str_starts_with($prefix, 'BM') => 'image/bmp',
            str_starts_with($prefix, 'RIFF') && str_contains(substr($contents, 0, 12), 'WEBP') => 'image/webp',
            str_starts_with($prefix, '{') || str_starts_with($prefix, '[') => 'application/json',
            default => null,
        };
    }

    private static function fromExtension(string $filename): string
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'txt' => 'text/plain',
            'html', 'htm' => 'text/html',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'zip' => 'application/zip',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }
}
