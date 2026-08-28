<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Extraction;

enum DiscoveryRelationship: string
{
    case Link = 'link';
    case Iframe = 'iframe';
    case Embed = 'embed';
}
