<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Contracts;

use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;

interface Backfills
{
    public function backfill(OperationRequest $request): OperationResult;
}
