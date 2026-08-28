<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

interface GitRepositorySource
{
    public function repository(): GitRepository;

    public function snapshot(string $ref, ?string $previousHeadSha): GitSnapshot;
}
