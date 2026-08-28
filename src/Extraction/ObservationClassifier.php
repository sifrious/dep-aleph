<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Extraction;

use Sifrious\Aleph\Web\FetchResult;

final readonly class ObservationClassifier
{
    public function classify(FetchResult $observation): ObservationFormat
    {
        $contentType = strtolower(trim(explode(';', $observation->contentType ?? '', 2)[0]));

        return match ($contentType) {
            'text/html', 'application/xhtml+xml' => ObservationFormat::Html,
            'application/pdf' => ObservationFormat::Pdf,
            default => ObservationFormat::Unsupported,
        };
    }
}
