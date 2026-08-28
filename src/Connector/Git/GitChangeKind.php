<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

enum GitChangeKind: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
}
