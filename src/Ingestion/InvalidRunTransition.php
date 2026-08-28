<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use LogicException;

final class InvalidRunTransition extends LogicException
{
    public static function from(RunStatus $from, RunStatus $to): self
    {
        return new self("An ingestion run cannot transition from {$from->value} to {$to->value}.");
    }
}
