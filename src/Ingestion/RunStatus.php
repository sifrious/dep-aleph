<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum RunStatus: string
{
    case Running = 'running';
    case Interrupted = 'interrupted';
    case Completed = 'completed';
}
