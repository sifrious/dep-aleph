<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

enum RedactionDecision: string
{
    case Retained = 'retained';
    case Redacted = 'redacted';
}
