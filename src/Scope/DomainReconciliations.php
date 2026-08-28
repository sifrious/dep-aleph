<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Scope;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Funes\Value\EntityKind;
use Sifrious\Funes\Value\EntityReference;

final readonly class DomainReconciliations
{
    private const string MarkerRole = 'domain-reconciliation';

    private const string AssociationRole = 'domain-association';

    public function __construct(private SourceScopeAssociations $scopes) {}

    /** @param list<EntityReference> $references */
    public function reconcile(
        string $sourceInstallationId,
        EntityReference $domain,
        AssociationState $state,
        array $references,
        string $decidedBy,
        DateTimeImmutable $decidedAt,
    ): DomainReconciliation {
        $this->validate($domain, $state, $references, $decidedBy);
        $metadata = ['decided_by' => $decidedBy, 'decided_at' => $decidedAt->format(DATE_ATOM)];
        $existing = $this->scopes->forInstallation($sourceInstallationId, $domain->id);
        $incoming = array_map(static fn (EntityReference $reference): string => $reference->kind->value.':'.$reference->id, $references);

        foreach ($existing as $association) {
            $identity = $association->scope->kind->value.':'.$association->scope->id;

            if ($association->role === self::AssociationRole
                && ! in_array($identity, $incoming, true)
                && $association->state !== AssociationState::Superseded) {
                $this->scopes->associate(
                    $sourceInstallationId,
                    $association->scope,
                    AssociationState::Superseded,
                    $domain->id,
                    self::AssociationRole,
                    array_merge($association->metadata, [
                        'superseded_by' => $decidedBy,
                        'superseded_at' => $decidedAt->format(DATE_ATOM),
                    ]),
                );
            }
        }

        foreach ($references as $reference) {
            $this->scopes->associate(
                $sourceInstallationId,
                $reference,
                $state,
                $domain->id,
                self::AssociationRole,
                $metadata,
            );
        }

        $this->scopes->associate(
            $sourceInstallationId,
            $domain,
            $state,
            $domain->id,
            self::MarkerRole,
            $metadata,
        );

        return $this->find($sourceInstallationId, $domain->id);
    }

    public function find(string $sourceInstallationId, string $domainReference): DomainReconciliation
    {
        $associations = $this->scopes->forInstallation($sourceInstallationId, $domainReference);
        $marker = null;
        $related = [];

        foreach ($associations as $association) {
            if ($association->stream !== $domainReference) {
                continue;
            }

            if ($association->role === self::MarkerRole) {
                $marker = $association;
            } elseif ($association->role === self::AssociationRole) {
                $related[] = $association;
            }
        }

        if ($marker === null) {
            throw new InvalidArgumentException("Domain reconciliation [{$domainReference}] does not exist.");
        }

        $decidedBy = $marker->metadata['decided_by'] ?? null;
        $decidedAt = $marker->metadata['decided_at'] ?? null;

        if (! is_string($decidedBy) || ! is_string($decidedAt)) {
            throw new InvalidArgumentException('Domain reconciliation decision metadata is incomplete.');
        }

        return new DomainReconciliation(
            $sourceInstallationId,
            $marker->scope,
            $marker->state,
            $decidedBy,
            new DateTimeImmutable($decidedAt),
            $related,
        );
    }

    /** @return array<string, list<DomainReconciliation>> */
    public function groupedByState(string $sourceInstallationId): array
    {
        $grouped = array_fill_keys(array_map(static fn (AssociationState $state): string => $state->value, AssociationState::cases()), []);

        foreach ($this->scopes->allForInstallation($sourceInstallationId) as $association) {
            if ($association->role !== self::MarkerRole || $association->stream === null) {
                continue;
            }

            $grouped[$association->state->value][] = $this->find($sourceInstallationId, $association->stream);
        }

        return $grouped;
    }

    /** @param list<EntityReference> $references */
    private function validate(EntityReference $domain, AssociationState $state, array $references, string $decidedBy): void
    {
        if ($domain->kind !== EntityKind::Domain || trim($decidedBy) === '') {
            throw new InvalidArgumentException('Domain reconciliation requires a domain reference and decision actor.');
        }

        if ($state === AssociationState::Unassigned && $references !== []) {
            throw new InvalidArgumentException('Unassigned domains cannot carry candidate associations.');
        }

        if ($state === AssociationState::Ambiguous && count($references) < 2) {
            throw new InvalidArgumentException('Ambiguous domains require at least two candidate associations.');
        }

        if (! in_array($state, [AssociationState::Unassigned, AssociationState::Ambiguous], true) && $references === []) {
            throw new InvalidArgumentException('Resolved domain decisions require at least one referenced association.');
        }

        foreach ($references as $reference) {
            if (! in_array($reference->kind, [EntityKind::Project, EntityKind::Site, EntityKind::Repository], true)) {
                throw new InvalidArgumentException('Domain associations support stable project, site, and repository references.');
            }
        }
    }
}
