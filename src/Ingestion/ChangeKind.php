<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum ChangeKind: string
{
    case Added = 'added';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Unchanged = 'unchanged';
}
