<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Extraction;

enum ObservationFormat: string
{
    case Html = 'html';
    case Pdf = 'pdf';
    case Unsupported = 'unsupported';
}
