<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Contracts;

use Sifrious\Aleph\Connector\Values\HealthReport;

interface ChecksHealth
{
    public function checkHealth(): HealthReport;
}
