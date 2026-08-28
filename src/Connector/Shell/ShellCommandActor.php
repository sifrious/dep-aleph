<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

enum ShellCommandActor: string
{
    case Human = 'human';
    case Agent = 'agent';
}
