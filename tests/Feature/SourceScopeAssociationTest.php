<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Scope\AssociationState;
use Sifrious\Aleph\Scope\SourceScopeAssociations;
use Sifrious\Aleph\Scope\UnknownSourceInstallation;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;
use Sifrious\Funes\Value\EntityKind;
use Sifrious\Funes\Value\EntityReference;

function scopedInstallation(): string
{
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);

    return app(ConnectorInstallations::class)->create($connector, 'Scoped source')->id;
}

it('associates a source installation with an exact durable reference', function (): void {
    $installationId = scopedInstallation();

    $association = app(SourceScopeAssociations::class)->associate(
        $installationId,
        new EntityReference(EntityKind::Project, 'funes:project/01K4PROJECT'),
        role: 'primary',
    );

    expect($association->sourceInstallationId)->toBe($installationId)
        ->and($association->scope->toArray())->toBe([
            'kind' => 'project',
            'id' => 'funes:project/01K4PROJECT',
        ])
        ->and($association->state)->toBe(AssociationState::Confirmed);
});

it('reports an installation with no active association as unassigned', function (): void {
    $snapshot = app(SourceScopeAssociations::class)->snapshot(scopedInstallation());

    expect($snapshot)->toBe(['state' => 'unassigned', 'associations' => []]);
});

it('preserves every candidate in an ambiguous association', function (): void {
    $installationId = scopedInstallation();
    $scopes = app(SourceScopeAssociations::class);
    $scopes->associate(
        $installationId,
        new EntityReference(EntityKind::Project, 'linear:project/A'),
        AssociationState::Ambiguous,
    );
    $scopes->associate(
        $installationId,
        new EntityReference(EntityKind::Project, 'linear:project/B'),
        AssociationState::Ambiguous,
    );

    $snapshot = $scopes->snapshot($installationId);

    expect($snapshot['state'])->toBe('ambiguous')
        ->and($snapshot['associations'])->toHaveCount(2);
});

it('preserves many confirmed scopes without selecting one', function (): void {
    $installationId = scopedInstallation();
    $scopes = app(SourceScopeAssociations::class);
    $scopes->associate(
        $installationId,
        new EntityReference(EntityKind::Domain, 'dns:domain/example.com'),
        role: 'owned_domain',
    );
    $scopes->associate(
        $installationId,
        new EntityReference(EntityKind::Repository, 'github:R_kgDOExample'),
        role: 'deployment_source',
    );

    $snapshot = $scopes->snapshot($installationId);

    expect($snapshot['state'])->toBe('assigned')
        ->and($snapshot['associations'])->toHaveCount(2)
        ->and(array_column(array_column($snapshot['associations'], 'scope'), 'kind'))
        ->toBe(['domain', 'repository']);
});

it('refuses an association for an installation that does not exist', function (): void {
    expect(fn () => app(SourceScopeAssociations::class)->associate(
        '01K4MISSINGINSTALLATION0000',
        new EntityReference(EntityKind::Domain, 'dns:domain/example.com'),
    ))->toThrow(UnknownSourceInstallation::class);
});

it('replays an imported association without creating a second row', function (): void {
    $installationId = scopedInstallation();
    $scopes = app(SourceScopeAssociations::class);
    $reference = new EntityReference(EntityKind::Organization, 'github:organization/123');

    $first = $scopes->associate($installationId, $reference, metadata: ['source' => 'landing']);
    $replayed = $scopes->associate($installationId, $reference, metadata: ['source' => 'landing']);

    expect($replayed->id)->toBe($first->id)
        ->and(DB::table('aleph_source_scope_associations')->count())->toBe(1);
});

it('isolates stream scopes while inheriting installation scopes', function (): void {
    $installationId = scopedInstallation();
    $scopes = app(SourceScopeAssociations::class);
    $scopes->associate(
        $installationId,
        new EntityReference(EntityKind::Organization, 'slack:workspace/T1'),
    );
    $scopes->associate(
        $installationId,
        new EntityReference(EntityKind::Project, 'linear:project/P1'),
        stream: 'channel:C1',
    );
    $scopes->associate(
        $installationId,
        new EntityReference(EntityKind::Project, 'linear:project/P2'),
        stream: 'channel:C2',
    );

    $channelOne = $scopes->forInstallation($installationId, 'channel:C1');

    expect($channelOne)->toHaveCount(2)
        ->and(array_map(fn ($association): string => $association->scope->id, $channelOne))
        ->toBe(['slack:workspace/T1', 'linear:project/P1']);
});

it('snapshots account and scope provenance into accepted history', function (): void {
    $installationId = scopedInstallation();
    app(SourceScopeAssociations::class)->associate(
        $installationId,
        new EntityReference(EntityKind::Identity, 'slack:user/U123'),
        stream: 'channel:C1',
        role: 'author',
    );

    app(EnvelopeSubmitter::class)->submit(new ObservationEnvelope(
        sourceReference: 'slack:workspace/T1',
        sourceName: 'Workspace T1',
        resourceReference: 'slack:message/123',
        observedAt: new DateTimeImmutable('2026-08-28T12:00:00+00:00'),
        payload: 'hello',
        provenance: new Provenance('slack', '1.0.0', $installationId, new DateTimeImmutable('2026-08-28T12:01:00+00:00')),
        account: 'workspace:T1',
        stream: 'channel:C1',
    ));

    $metadata = json_decode((string) DB::table('funes_observations')->value('metadata'), true);

    expect($metadata['aleph']['account'])->toBe('workspace:T1')
        ->and($metadata['aleph']['source_scopes']['state'])->toBe('assigned')
        ->and($metadata['aleph']['source_scopes']['associations'][0]['scope'])->toBe([
            'kind' => 'identity',
            'id' => 'slack:user/U123',
        ]);
});

it('uses only package contracts when instantiated by another host', function (): void {
    $installationId = scopedInstallation();
    $hostRepository = new SourceScopeAssociations(DB::connection());

    $association = $hostRepository->associate(
        $installationId,
        new EntityReference(EntityKind::Domain, 'dns:domain/example.net'),
    );

    expect($association->scope->id)->toBe('dns:domain/example.net')
        ->and(get_debug_type($association))->toBe('Sifrious\\Aleph\\Scope\\SourceScopeAssociation');
});
