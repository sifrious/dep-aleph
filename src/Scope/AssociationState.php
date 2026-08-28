<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Scope;

enum AssociationState: string
{
    case Confirmed = 'confirmed';
    case Ambiguous = 'ambiguous';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
