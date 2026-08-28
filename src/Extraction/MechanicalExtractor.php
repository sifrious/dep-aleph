<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Extraction;

use Sifrious\Aleph\Web\FetchResult;

interface MechanicalExtractor
{
    public function format(): ObservationFormat;

    public function name(): string;

    public function version(): string;

    public function extract(FetchResult $observation): MechanicalExtraction;
}
