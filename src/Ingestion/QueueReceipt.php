<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class QueueReceipt
{
    public function __construct(public string $jobId)
    {
        if (trim($jobId) === '') {
            throw new InvalidArgumentException('A queue receipt requires a stable job identity.');
        }
    }
}
