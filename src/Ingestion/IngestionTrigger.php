<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum IngestionTrigger: string
{
    case System = 'system';
    case Manual = 'manual';
}
