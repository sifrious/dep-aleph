<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use DateTimeImmutable;
use RuntimeException;

final class GitHubRateLimited extends RuntimeException
{
    public function __construct(public readonly DateTimeImmutable $retryAt)
    {
        parent::__construct('GitHub rate limit exhausted until '.$retryAt->format(DATE_ATOM).'.');
    }
}
