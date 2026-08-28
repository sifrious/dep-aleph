<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use DateTimeImmutable;

final readonly class LandingSlackTokenRecord
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $legacyId,
        public string $sourceInstallationId,
        public string $workspaceReference,
        public ?string $accountReference,
        public ?string $secretReference,
        public array $scopes,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt,
        public ?DateTimeImmutable $refreshedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
