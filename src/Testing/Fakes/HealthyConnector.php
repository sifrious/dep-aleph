<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Testing\Fakes;

use Sifrious\Aleph\Connector\Contracts\ChecksHealth;
use Sifrious\Aleph\Connector\Values\HealthReport;

final class HealthyConnector extends BaseFakeConnector implements ChecksHealth
{
    public function checkHealth(): HealthReport
    {
        return HealthReport::healthy('fake connector reachable');
    }
}
