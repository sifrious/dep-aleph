<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Extraction;

enum ExtractionStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
