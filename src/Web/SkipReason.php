<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

enum SkipReason: string
{
    case ExternalHost = 'external_host';
    case Excluded = 'excluded';
    case DepthLimit = 'depth_limit';
    case RedirectAlias = 'redirect_alias';
}
