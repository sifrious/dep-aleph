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

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next === self::Running,
            self::Running => in_array($next, [self::Partial, self::Interrupted, self::Failed, self::Canceled, self::Completed], true),
            self::Partial, self::Interrupted => $next === self::Running,
            self::Failed => $next === self::Running,
            self::Canceled, self::Completed => false,
        };
    }
}
