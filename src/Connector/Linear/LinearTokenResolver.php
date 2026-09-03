<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

interface LinearTokenResolver
{
    public function resolve(string $sourceInstallationId): LinearTokenSecret;
}
