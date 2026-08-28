<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum RunCompleteness: string
{
    case Incomplete = 'incomplete';
    case Partial = 'partial';
    case Complete = 'complete';
}
