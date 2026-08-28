<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Watch;

enum RepositoryWatchStatus: string
{
    case Disabled = 'disabled';
    case Backoff = 'backoff';
    case Error = 'error';
    case Due = 'due';
    case Scheduled = 'scheduled';
}
