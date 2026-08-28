<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum CheckpointRule: string
{
    case Replace = 'replace';
    case Monotonic = 'monotonic';
}
