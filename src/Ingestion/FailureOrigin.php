<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum FailureOrigin: string
{
    case Domain = 'domain';
    case Queue = 'queue';
}
