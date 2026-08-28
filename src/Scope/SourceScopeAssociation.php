<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Scope;

use DateTimeImmutable;
use Sifrious\Funes\Value\EntityReference;

final readonly class SourceScopeAssociation
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $sourceInstallationId,
        public ?string $stream,
        public EntityReference $scope,
        public AssociationState $state,
        public ?string $role,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'source_installation' => $this->sourceInstallationId,
            'stream' => $this->stream,
            'scope' => $this->scope->toArray(),
            'state' => $this->state->value,
            'role' => $this->role,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
