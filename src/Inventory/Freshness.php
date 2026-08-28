<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Inventory;

enum Freshness: string
{
    case Current = 'current';
    case Stale = 'stale';
    case Unobserved = 'unobserved';
}
