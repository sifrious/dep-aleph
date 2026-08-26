<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

enum FrontierState: string
{
    case Pending = 'pending';
    case Fetching = 'fetching';
    case Fetched = 'fetched';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
