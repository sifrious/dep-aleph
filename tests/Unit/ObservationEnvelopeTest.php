<?php

declare(strict_types=1);

use Sifrious\Aleph\Envelope\ArtifactReference;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

function envelope(array $overrides = []): ObservationEnvelope
{
    return new ObservationEnvelope(
        sourceReference: $overrides['sourceReference'] ?? 'weird:stream/one',
        sourceName: 'Weird Stream',
        resourceReference: $overrides['resourceReference'] ?? 'weird:item/1',
        observedAt: new DateTimeImmutable('2026-08-27T09:00:00+00:00'),
        payload: 'body',
        provenance: new Provenance('weird', '1.0.0', 'inst-1', new DateTimeImmutable('2026-08-27T09:05:00+00:00')),
        contentType: 'text/plain',
        account: 'acct-1',
        stream: 'stream/one',
        eventType: 'weird.thing.happened',
        providerId: 'W-1',
        providerRevision: '4',
        artifacts: $overrides['artifacts'] ?? [],
        extensions: $overrides['extensions'] ?? [],
    );
}

it('stamps the envelope schema version into metadata', function (): void {
    expect(envelope()->metadata()['aleph']['envelope_version'])->toBe(ObservationEnvelope::SCHEMA_VERSION);
});

it('carries provider identity under the reserved aleph namespace', function (): void {
    $aleph = envelope()->metadata()['aleph'];

    expect($aleph)->toMatchArray([
        'account' => 'acct-1',
        'stream' => 'stream/one',
        'event_type' => 'weird.thing.happened',
        'provider_id' => 'W-1',
        'provider_revision' => '4',
    ])->and($aleph['provenance'])->toMatchArray([
        'connector' => 'weird',
        'connector_version' => '1.0.0',
        'installation' => 'inst-1',
    ]);
});

it('keeps extensions separate from the reserved namespace', function (): void {
    $metadata = envelope(['extensions' => [
        new ExtensionMetadata('weirdservice.widget', 2, ['ring' => 'RN-1']),
    ]])->metadata();

    expect($metadata['extensions'])->toBe([
        ['namespace' => 'weirdservice.widget', 'version' => 2, 'data' => ['ring' => 'RN-1']],
    ])->and($metadata['aleph'])->not->toHaveKey('weirdservice.widget');
});

it('refuses two extensions sharing a namespace', function (): void {
    envelope(['extensions' => [
        new ExtensionMetadata('weirdservice.widget', 1, []),
        new ExtensionMetadata('weirdservice.widget', 2, []),
    ]]);
})->throws(InvalidArgumentException::class, 'declared more than once');

it('records artifact references without inventing Funes columns', function (): void {
    $metadata = envelope(['artifacts' => [
        new ArtifactReference('weird:file/1', 'attachment', 'application/pdf'),
    ]])->metadata();

    expect($metadata['aleph']['artifacts'])->toBe([
        ['reference' => 'weird:file/1', 'relationship' => 'attachment', 'media_type' => 'application/pdf'],
    ]);
});

it('finds an extension by namespace', function (): void {
    $envelope = envelope(['extensions' => [new ExtensionMetadata('weirdservice.widget', 2, ['a' => 1])]]);

    expect($envelope->extension('weirdservice.widget')?->version)->toBe(2)
        ->and($envelope->extension('absent'))->toBeNull();
});

it('refuses empty source or resource references', function (): void {
    envelope(['resourceReference' => '']);
})->throws(InvalidArgumentException::class);
