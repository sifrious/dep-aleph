<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

enum DiscoveryOrigin: string
{
    case Seed = 'seed';
    case Link = 'link';
}
