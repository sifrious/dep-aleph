<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

enum DomainRunScope: string
{
    case Account = 'account';
    case Domain = 'domain';
}
