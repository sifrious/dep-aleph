<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

enum HttpMethod: string
{
    case Get = 'GET';
    case Head = 'HEAD';
}
