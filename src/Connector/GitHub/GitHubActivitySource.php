<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

interface GitHubActivitySource
{
    public function sourceReference(): string;

    public function page(string $repository, ?string $cursor, int $limit): GitHubActivityPage;
}
