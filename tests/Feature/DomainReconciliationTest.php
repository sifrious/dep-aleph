<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Scope\AssociationState;
use Sifrious\Aleph\Scope\DomainReconciliations;
use Sifrious\Aleph\Scope\SourceScopeAssociations;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;
use Sifrious\Funes\Value\EntityKind;
use Sifrious\Funes\Value\EntityReference;

function domainReference(string $name): EntityReference
{
    return new EntityReference(EntityKind::Domain, 'dns:domain/'.$name);
}

function reconciliationInstallation(): string
{
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);

    return app(ConnectorInstallations::class)->create($connector, 'Domain reconciliation source')->id;
}

function projectReference(string $id): EntityReference
{
    return new EntityReference(EntityKind::Project, 'vault:project/'.$id);
}

function siteReference(string $id): EntityReference
{
    return new EntityReference(EntityKind::Site, 'vault:site/'.$id);
}

it('records exact domain reconciliation with stable source-scope references and decision evidence', function (): void {
    $installationId = reconciliationInstallation();
    $decidedAt = new DateTimeImmutable('2026-08-28T12:00:00Z');
    $reconciliation = app(DomainReconciliations::class)->reconcile(
        $installationId,
        domainReference('example.com'),
        AssociationState::Confirmed,
        [projectReference('sites/example')],
        'operator:mary',
        $decidedAt,
    );

    expect($reconciliation->domain->id)->toBe('dns:domain/example.com')
        ->and($reconciliation->state)->toBe(AssociationState::Confirmed)
        ->and($reconciliation->decidedBy)->toBe('operator:mary')
        ->and($reconciliation->decidedAt)->toEqual($decidedAt)
        ->and($reconciliation->associations[0]->scope->id)->toBe('vault:project/sites/example')
        ->and($reconciliation->associations[0]->role)->toBe('domain-association');
});

it('represents unassigned domains without inferring a project from their names', function (): void {
    $reconciliation = app(DomainReconciliations::class)->reconcile(
        reconciliationInstallation(),
        domainReference('landing.example'),
        AssociationState::Unassigned,
        [],
        'reconciler:fixture',
        new DateTimeImmutable('2026-08-28T12:00:00Z'),
    );

    expect($reconciliation->state)->toBe(AssociationState::Unassigned)
        ->and($reconciliation->associations)->toBe([])
        ->and(json_encode($reconciliation->toArray(), JSON_THROW_ON_ERROR))->not->toContain('project/landing');
});

it('preserves ambiguous and many-to-many project and repository candidates', function (): void {
    $installationId = reconciliationInstallation();
    $domain = domainReference('shared.example');
    $projects = [projectReference('sites/alpha'), projectReference('sites/beta')];
    $ambiguous = app(DomainReconciliations::class)->reconcile(
        $installationId,
        $domain,
        AssociationState::Ambiguous,
        $projects,
        'reconciler:candidates',
        new DateTimeImmutable('2026-08-28T12:00:00Z'),
    );
    $confirmed = app(DomainReconciliations::class)->reconcile(
        $installationId,
        $domain,
        AssociationState::Confirmed,
        [
            $projects[0],
            siteReference('sites/alpha'),
            new EntityReference(EntityKind::Repository, 'github:repository/R_example'),
        ],
        'operator:mary',
        new DateTimeImmutable('2026-08-28T13:00:00Z'),
    );

    expect($ambiguous->associations)->toHaveCount(2)
        ->and($confirmed->state)->toBe(AssociationState::Confirmed)
        ->and($confirmed->associations)->toHaveCount(4)
        ->and(array_map(static fn ($association): string => $association->state->value, $confirmed->associations))
        ->toContain('confirmed', 'superseded');
});

it('represents rejected and superseded decisions without deleting prior references', function (): void {
    $installationId = reconciliationInstallation();
    $domain = domainReference('changed.example');
    $first = projectReference('sites/old');
    $second = projectReference('sites/new');
    $reconciliations = app(DomainReconciliations::class);
    $reconciliations->reconcile($installationId, $domain, AssociationState::Rejected, [$first], 'operator:mary', new DateTimeImmutable('2026-08-28T12:00:00Z'));
    $changed = $reconciliations->reconcile($installationId, $domain, AssociationState::Confirmed, [$second], 'operator:mary', new DateTimeImmutable('2026-08-28T13:00:00Z'));
    $superseded = array_values(array_filter($changed->associations, static fn ($association): bool => $association->state === AssociationState::Superseded))[0];

    expect($changed->associations)->toHaveCount(2)
        ->and($superseded->metadata)->toMatchArray([
            'decided_by' => 'operator:mary',
            'superseded_by' => 'operator:mary',
        ])
        ->and(array_map(static fn ($association): string => $association->state->value, $changed->associations))
        ->toContain('confirmed', 'superseded');
});

it('replays the same reconciliation without changing identity or decision time', function (): void {
    $installationId = reconciliationInstallation();
    $domain = domainReference('repeat.example');
    $project = projectReference('sites/repeat');
    $decidedAt = new DateTimeImmutable('2026-08-28T12:00:00Z');
    $reconciliations = app(DomainReconciliations::class);
    $first = $reconciliations->reconcile($installationId, $domain, AssociationState::Confirmed, [$project], 'operator:mary', $decidedAt);
    $replayed = $reconciliations->reconcile($installationId, $domain, AssociationState::Confirmed, [$project], 'operator:mary', $decidedAt);

    expect($replayed->associations[0]->id)->toBe($first->associations[0]->id)
        ->and($replayed->associations[0]->updatedAt)->toEqual($first->associations[0]->updatedAt)
        ->and(app(SourceScopeAssociations::class)->allForInstallation($installationId))->toHaveCount(2);
});

it('returns a presentation-neutral query grouped by explicit association state', function (): void {
    $installationId = reconciliationInstallation();
    $reconciliations = app(DomainReconciliations::class);
    $reconciliations->reconcile($installationId, domainReference('assigned.example'), AssociationState::Confirmed, [projectReference('sites/assigned')], 'operator:mary', new DateTimeImmutable('2026-08-28T12:00:00Z'));
    $reconciliations->reconcile($installationId, domainReference('unassigned.example'), AssociationState::Unassigned, [], 'operator:mary', new DateTimeImmutable('2026-08-28T12:01:00Z'));
    $grouped = $reconciliations->groupedByState($installationId);

    expect(array_keys($grouped))->toBe(['unassigned', 'confirmed', 'ambiguous', 'rejected', 'superseded'])
        ->and($grouped['confirmed'][0]->domain->id)->toBe('dns:domain/assigned.example')
        ->and($grouped['unassigned'][0]->domain->id)->toBe('dns:domain/unassigned.example');
});
