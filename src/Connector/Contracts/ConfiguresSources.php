<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Contracts;

use Sifrious\Aleph\Connector\Configuration\SourceConfiguration;
use Sifrious\Aleph\Connector\Configuration\SourceConfigurationRequest;

interface ConfiguresSources
{
    public function configureSource(SourceConfigurationRequest $request): SourceConfiguration;
}
