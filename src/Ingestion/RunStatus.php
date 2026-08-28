<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Partial = 'partial';
    case Interrupted = 'interrupted';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Completed = 'completed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Failed, self::Canceled, self::Completed], true);
    }
}
