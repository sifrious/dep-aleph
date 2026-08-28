<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Scope;

use DateTimeImmutable;
use Sifrious\Funes\Value\EntityReference;

final readonly class DomainReconciliation
{
    /** @param list<SourceScopeAssociation> $associations */
    public function __construct(
        public string $sourceInstallationId,
        public EntityReference $domain,
        public AssociationState $state,
        public string $decidedBy,
        public DateTimeImmutable $decidedAt,
        public array $associations,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_installation' => $this->sourceInstallationId,
            'domain' => $this->domain->toArray(),
            'state' => $this->state->value,
            'decided_by' => $this->decidedBy,
            'decided_at' => $this->decidedAt->format(DATE_ATOM),
            'associations' => array_map(
                static fn (SourceScopeAssociation $association): array => $association->toArray(),
                $this->associations,
            ),
        ];
    }
}
