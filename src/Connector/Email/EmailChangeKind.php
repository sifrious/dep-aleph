<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

enum EmailChangeKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
