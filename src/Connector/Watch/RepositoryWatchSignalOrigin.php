<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Watch;

enum RepositoryWatchSignalOrigin: string
{
    case Webhook = 'webhook';
    case Poll = 'poll';
    case Reconciliation = 'reconciliation';
}
