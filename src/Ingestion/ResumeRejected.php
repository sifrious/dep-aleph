<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use RuntimeException;

final class ResumeRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
