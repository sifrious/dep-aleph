<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

enum StopReason: string
{
    case FrontierExhausted = 'frontier_exhausted';
    case PageLimit = 'page_limit';
}
