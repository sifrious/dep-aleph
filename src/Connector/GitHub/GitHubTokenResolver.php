<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

interface GitHubTokenResolver
{
    public function resolve(string $sourceInstallationId): GitHubTokenSecret;
}
