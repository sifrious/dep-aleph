<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum SyncStrategy: string
{
    case Cursor = 'cursor';
    case HighWater = 'high_water';
    case Webhook = 'webhook';
    case Hash = 'hash';
    case Reconcile = 'reconcile';

    public function requiresPeriodicReconciliation(): bool
    {
        return in_array($this, [self::Webhook, self::Hash, self::Reconcile], true);
    }
}
