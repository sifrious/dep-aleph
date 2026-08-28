<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

enum CommunicationChange: string
{
    case Created = 'created';
    case Edited = 'edited';
    case Deleted = 'deleted';
    case Unsupported = 'unsupported';
    case Unavailable = 'unavailable';
}
