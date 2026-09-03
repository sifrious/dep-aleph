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

    public const VERSION = '2';

    public function __construct(private Parser $pdf) {}

    public function supports(string $mediaType): bool
    {
        return in_array(strtolower(trim($mediaType)), [
            GoogleDriveExportPlan::DOCX,
            GoogleDriveExportPlan::XLSX,
            GoogleDriveExportPlan::PPTX,
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
            GoogleDriveExportPlan::XLSX => $this->xlsx($request->contents),
            GoogleDriveExportPlan::PPTX => $this->pptx($request->contents),
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
        return $this->withArchive($contents, 'DOCX', function (ZipArchive $archive): string {
            $xml = $archive->getFromName('word/document.xml');

            if (! is_string($xml)) {
                throw new RuntimeException('The DOCX archive has no word/document.xml part.');
            }

            return $this->paragraphText(
                $xml,
                'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                '//o:p',
                './/o:t',
                'DOCX',
            );
        });
    }

    private function xlsx(string $contents): string
    {
        return $this->withArchive($contents, 'XLSX', function (ZipArchive $archive): string {
            $sharedStrings = [];
            $sharedXml = $archive->getFromName('xl/sharedStrings.xml');

            if (is_string($sharedXml)) {
                $document = $this->xml($sharedXml, 'XLSX shared strings');
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('o', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

                foreach ($xpath->query('//o:si') ?: [] as $item) {
                    if (! $item instanceof DOMElement) {
                        continue;
                    }

                    $parts = [];

                    foreach ($xpath->query('.//o:t', $item) ?: [] as $text) {
                        if ($text instanceof DOMElement) {
                            $parts[] = $text->textContent;
                        }
                    }

                    $sharedStrings[] = implode('', $parts);
                }
            }

            $sheetNames = $this->archiveParts($archive, '#^xl/worksheets/sheet[0-9]+\.xml$#');
            $sheets = [];

            foreach ($sheetNames as $sheetName) {
                $xml = $archive->getFromName($sheetName);

                if (! is_string($xml)) {
                    continue;
                }

                $document = $this->xml($xml, 'XLSX worksheet');
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('o', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $rows = [];

                foreach ($xpath->query('//o:sheetData/o:row') ?: [] as $row) {
                    if (! $row instanceof DOMElement) {
                        continue;
                    }

                    $cells = [];

                    foreach ($xpath->query('./o:c', $row) ?: [] as $cell) {
                        if (! $cell instanceof DOMElement) {
                            continue;
                        }

                        $type = $cell->getAttribute('t');
                        $value = trim((string) $xpath->evaluate('string(./o:v)', $cell));

                        if ($type === 'inlineStr') {
                            $value = trim((string) $xpath->evaluate('string(./o:is)', $cell));
                        } elseif ($type === 's' && ctype_digit($value)) {
                            $value = $sharedStrings[(int) $value] ?? '';
                        } elseif ($type === 'b') {
                            $value = $value === '1' ? 'TRUE' : 'FALSE';
                        }

                        $cells[] = $value;
                    }

                    $rows[] = rtrim(implode("\t", $cells));
                }

                $sheets[] = implode("\n", $rows);
            }

            if ($sheetNames === []) {
                throw new RuntimeException('The XLSX archive has no worksheets.');
            }

            return trim(implode("\n\n", $sheets));
        });
    }

    private function pptx(string $contents): string
    {
        return $this->withArchive($contents, 'PPTX', function (ZipArchive $archive): string {
            $slideNames = $this->archiveParts($archive, '#^ppt/slides/slide[0-9]+\.xml$#');

            if ($slideNames === []) {
                throw new RuntimeException('The PPTX archive has no slides.');
            }

            $slides = [];

            foreach ($slideNames as $slideName) {
                $xml = $archive->getFromName($slideName);

                if (is_string($xml)) {
                    $slides[] = $this->paragraphText(
                        $xml,
                        'http://schemas.openxmlformats.org/drawingml/2006/main',
                        '//o:p',
                        './/o:t',
                        'PPTX slide',
                    );
                }
            }

            return trim(implode("\n\n", $slides));
        });
    }

    private function paragraphText(
        string $xml,
        string $namespace,
        string $paragraphQuery,
        string $textQuery,
        string $label,
    ): string {
        $document = $this->xml($xml, $label);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('o', $namespace);
        $paragraphs = [];

        foreach ($xpath->query($paragraphQuery) ?: [] as $paragraph) {
            if (! $paragraph instanceof DOMElement) {
                continue;
            }

            $parts = [];

            foreach ($xpath->query($textQuery, $paragraph) ?: [] as $text) {
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

    /** @return list<string> */
    private function archiveParts(ZipArchive $archive, string $pattern): array
    {
        $parts = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);

            if (is_string($name) && preg_match($pattern, $name) === 1) {
                $parts[] = $name;
            }
        }

        natsort($parts);

        return array_values($parts);
    }

    private function xml(string $xml, string $label): DOMDocument
    {
        $document = new DOMDocument;

        if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException("The {$label} XML is invalid.");
        }

        return $document;
    }

    /**
     * @template T
     *
     * @param  callable(ZipArchive): T  $callback
     * @return T
     */
    private function withArchive(string $contents, string $format, callable $callback): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'aleph-document-');

        if ($path === false || file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Could not create a temporary {$format} file.");
        }

        try {
            $archive = new ZipArchive;

            if ($archive->open($path) !== true) {
                throw new RuntimeException("The {$format} archive could not be opened.");
            }

            try {
                return $callback($archive);
            } finally {
                $archive->close();
            }
        } finally {
            unlink($path);
        }
    }
}
