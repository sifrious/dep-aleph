<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

enum FetchFailure: string
{
    case ConnectionFailed = 'connection_failed';
    case Timeout = 'timeout';
    case TooLarge = 'too_large';
    case TooManyRedirects = 'too_many_redirects';
    case RobotsDisallowed = 'robots_disallowed';
}
