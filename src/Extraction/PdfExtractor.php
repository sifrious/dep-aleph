<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Extraction;

use Sifrious\Aleph\Web\FetchResult;
use Smalot\PdfParser\Parser;

final readonly class PdfExtractor implements MechanicalExtractor
{
    public function __construct(private Parser $parser) {}

    public function format(): ObservationFormat
    {
        return ObservationFormat::Pdf;
    }

    public function name(): string
    {
        return 'aleph.pdf';
    }

    public function version(): string
    {
        return '1';
    }

    public function extract(FetchResult $observation): MechanicalExtraction
    {
        $document = $this->parser->parseContent($observation->body ?? '');

        return new MechanicalExtraction(
            $this->format(),
            $this->name(),
            $this->version(),
            [
                'classification' => $this->format()->value,
                'text' => trim(mb_scrub($document->getText(), 'UTF-8')),
            ],
            [],
        );
    }
}
