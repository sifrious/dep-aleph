<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Contracts;

use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\OperationRequest;

interface DiscoversSources
{
    public function discoverSources(OperationRequest $request): DiscoveredSources;
}
