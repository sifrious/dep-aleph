<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use DateTimeImmutable;

final readonly class SlackSecretRotation
{
    /** @param list<string> $scopes */
    public function __construct(
        public SlackTokenSecret $accessToken,
        public ?DateTimeImmutable $expiresAt,
        public array $scopes = [],
    ) {}
}
