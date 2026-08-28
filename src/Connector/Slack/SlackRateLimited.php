<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use DateTimeImmutable;
use RuntimeException;

final class SlackRateLimited extends RuntimeException
{
    public function __construct(public readonly DateTimeImmutable $retryAt)
    {
        parent::__construct('Slack rate limit reached; retry after the supplied time.');
    }
}
