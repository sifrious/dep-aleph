<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum PartitionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Failed = 'failed';
    case Completed = 'completed';
}
