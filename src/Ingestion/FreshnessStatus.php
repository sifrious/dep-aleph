<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum FreshnessStatus: string
{
    case NeverSynchronized = 'never_synchronized';
    case Healthy = 'healthy';
    case Due = 'due';
    case Stale = 'stale';
}
