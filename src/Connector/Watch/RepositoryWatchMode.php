<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Watch;

enum RepositoryWatchMode: string
{
    case Poll = 'poll';
    case Webhook = 'webhook';
    case Hybrid = 'hybrid';
}
