<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Testing\Fakes;

use Sifrious\Aleph\Connector\Contracts\DiscoversSources;
use Sifrious\Aleph\Connector\Values\DiscoveredSource;
use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\OperationRequest;

final class DiscoveryConnector extends BaseFakeConnector implements DiscoversSources
{
    /** @var list<OperationRequest> */
    public array $discoveryCalls = [];

    public function discoverSources(OperationRequest $request): DiscoveredSources
    {
        $this->discoveryCalls[] = $request;

        return new DiscoveredSources(
            new DiscoveredSource($request->sourceReference.'/alpha', 'Alpha'),
            new DiscoveredSource($request->sourceReference.'/beta', 'Beta'),
        );
    }
}
