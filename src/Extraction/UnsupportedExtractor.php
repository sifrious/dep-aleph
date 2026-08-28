<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Extraction;

use Sifrious\Aleph\Web\FetchResult;

final readonly class UnsupportedExtractor implements MechanicalExtractor
{
    public function format(): ObservationFormat
    {
        return ObservationFormat::Unsupported;
    }

    public function name(): string
    {
        return 'aleph.unsupported';
    }

    public function version(): string
    {
        return '1';
    }

    public function extract(FetchResult $observation): MechanicalExtraction
    {
        return new MechanicalExtraction(
            $this->format(),
            $this->name(),
            $this->version(),
            [
                'classification' => $this->format()->value,
                'content_type' => $observation->contentType,
            ],
            [],
        );
    }
}
