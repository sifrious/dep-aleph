<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

use InvalidArgumentException;

/**
 * Maps Drive MIME types onto interchange exports for the existing MME-777 format tools.
 * Drive does not extract text; it only chooses a real interchange file.
 */
final class GoogleDriveExportPlan
{
    public const DOCS_MIME = 'application/vnd.google-apps.document';

    public const SHEETS_MIME = 'application/vnd.google-apps.spreadsheet';

    public const SLIDES_MIME = 'application/vnd.google-apps.presentation';

    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public const MARKDOWN = 'text/markdown';

    public const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const CSV = 'text/csv';

    public const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    public const PDF = 'application/pdf';

    /**
     * @return array{media_type: string, extension: string, export: bool}
     */
    public static function for(string $sourceMimeType, ?string $preferredExtension = null): array
    {
        $mime = strtolower(trim($sourceMimeType));
        $preferred = $preferredExtension === null ? null : strtolower(ltrim(trim($preferredExtension), '.'));

        return match ($mime) {
            self::DOCS_MIME => self::docs($preferred),
            self::SHEETS_MIME => self::sheets($preferred),
            self::SLIDES_MIME => self::slides($preferred),
            default => [
                'media_type' => $mime === '' ? 'application/octet-stream' : $mime,
                'extension' => self::extensionHint($mime, $preferred),
                'export' => false,
            ],
        };
    }

    public static function isNativeGoogleFormat(string $sourceMimeType): bool
    {
        return str_starts_with(strtolower(trim($sourceMimeType)), 'application/vnd.google-apps.');
    }

    /**
     * @return array{media_type: string, extension: string, export: bool}
     */
    private static function docs(?string $preferred): array
    {
        return match ($preferred) {
            'md', 'markdown' => ['media_type' => self::MARKDOWN, 'extension' => 'md', 'export' => true],
            null, '', 'docx' => ['media_type' => self::DOCX, 'extension' => 'docx', 'export' => true],
            default => throw new InvalidArgumentException('Google Docs exports support .docx or .md only.'),
        };
    }

    /**
     * @return array{media_type: string, extension: string, export: bool}
     */
    private static function sheets(?string $preferred): array
    {
        return match ($preferred) {
            'csv' => ['media_type' => self::CSV, 'extension' => 'csv', 'export' => true],
            null, '', 'xlsx' => ['media_type' => self::XLSX, 'extension' => 'xlsx', 'export' => true],
            default => throw new InvalidArgumentException('Google Sheets exports support .xlsx or .csv only.'),
        };
    }

    /**
     * @return array{media_type: string, extension: string, export: bool}
     */
    private static function slides(?string $preferred): array
    {
        return match ($preferred) {
            'pdf' => ['media_type' => self::PDF, 'extension' => 'pdf', 'export' => true],
            null, '', 'pptx' => ['media_type' => self::PPTX, 'extension' => 'pptx', 'export' => true],
            default => throw new InvalidArgumentException('Google Slides exports support .pptx or .pdf only.'),
        };
    }

    private static function extensionHint(string $mime, ?string $preferred): string
    {
        if ($preferred !== null && $preferred !== '') {
            return $preferred;
        }

        return match ($mime) {
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/markdown' => 'md',
            'text/html', 'application/xhtml+xml' => 'html',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            default => 'bin',
        };
    }
}
