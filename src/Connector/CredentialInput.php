<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use DateTimeImmutable;

final readonly class CredentialInput
{
    /**
     * @param  array<string, string>  $material
     * @param  list<string>  $scopes
     * @param  array<string, int|float|string|bool|null>  $refreshMetadata
     */
    public function __construct(
        public CredentialKind $kind,
        public array $material,
        public array $scopes = [],
        public array $refreshMetadata = [],
        public ?DateTimeImmutable $expiresAt = null,
    ) {}
}
