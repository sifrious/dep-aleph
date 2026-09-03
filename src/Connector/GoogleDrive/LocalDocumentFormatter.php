<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GoogleDrive;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Smalot\PdfParser\Parser;
use ZipArchive;

final readonly class LocalDocumentFormatter implements DocumentFormatter
{
    public const NAME = 'aleph.document.local';

    public const VERSION = '1';

    public function __construct(private Parser $pdf) {}

    public function supports(string $mediaType): bool
    {
        return in_array(strtolower(trim($mediaType)), [
            GoogleDriveExportPlan::DOCX,
            GoogleDriveExportPlan::PDF,
            GoogleDriveExportPlan::MARKDOWN,
            GoogleDriveExportPlan::CSV,
            'text/plain',
        ], true);
    }

    public function format(DocumentFormatHandoffRequest $request): FormattedDocument
    {
        if (! $this->supports($request->mediaType)) {
            throw new UnsupportedDocumentFormat("Document media type [{$request->mediaType}] is not supported.");
        }

        $text = match (strtolower(trim($request->mediaType))) {
            GoogleDriveExportPlan::DOCX => $this->docx($request->contents),
            GoogleDriveExportPlan::PDF => $this->pdf($request->contents),
            default => trim(mb_scrub($request->contents, 'UTF-8')),
        };

        return new FormattedDocument(self::NAME, self::VERSION, $text);
    }

    private function pdf(string $contents): string
    {
        return trim(mb_scrub($this->pdf->parseContent($contents)->getText(), 'UTF-8'));
    }

    private function docx(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aleph-docx-');

        if ($path === false || file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Could not create a temporary DOCX file.');
        }

        try {
            $archive = new ZipArchive;

            if ($archive->open($path) !== true) {
                throw new RuntimeException('The DOCX archive could not be opened.');
            }

            try {
                $xml = $archive->getFromName('word/document.xml');
            } finally {
                $archive->close();
            }

            if (! is_string($xml)) {
                throw new RuntimeException('The DOCX archive has no word/document.xml part.');
            }

            return $this->docxText($xml);
        } finally {
            unlink($path);
        }
    }

    private function docxText(string $xml): string
    {
        $document = new DOMDocument;

        if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('The DOCX document XML is invalid.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = [];

        foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
            if (! $paragraph instanceof DOMElement) {
                continue;
            }

            $parts = [];

            foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $text) {
                if ($text instanceof DOMElement) {
                    $parts[] = $text->textContent;
                }
            }

            $value = trim(implode('', $parts));

            if ($value !== '') {
                $paragraphs[] = $value;
            }
        }

        return implode("\n\n", $paragraphs);
    }
}
