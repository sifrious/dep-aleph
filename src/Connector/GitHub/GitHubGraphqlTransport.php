<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

interface GitHubGraphqlTransport
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(GitHubTokenSecret $token, string $document, array $variables): array;
}
