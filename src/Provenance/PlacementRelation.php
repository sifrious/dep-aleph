<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Provenance;

enum PlacementRelation: string
{
    case Preceding = 'preceding';
    case Same = 'same';
    case Following = 'following';
}
