<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

enum RunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Aborted = 'aborted';
}
