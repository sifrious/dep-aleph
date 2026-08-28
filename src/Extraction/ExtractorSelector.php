<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Extraction;

use Sifrious\Aleph\Web\FetchResult;
use Throwable;

final readonly class ExtractorSelector
{
    public function __construct(
        private ObservationClassifier $classifier,
        private HtmlExtractor $html,
        private PdfExtractor $pdf,
        private UnsupportedExtractor $unsupported,
    ) {}

    public function extract(FetchResult $observation): MechanicalExtraction
    {
        $extractor = match ($this->classifier->classify($observation)) {
            ObservationFormat::Html => $this->html,
            ObservationFormat::Pdf => $this->pdf,
            ObservationFormat::Unsupported => $this->unsupported,
        };

        try {
            return $extractor->extract($observation);
        } catch (Throwable $failure) {
            return new MechanicalExtraction(
                $extractor->format(),
                $extractor->name(),
                $extractor->version(),
                null,
                [],
                $failure->getMessage(),
            );
        }
    }
}
