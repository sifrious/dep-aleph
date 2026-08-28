<?php

declare(strict_types=1);

use Sifrious\Aleph\Acceptance\Backfill;
use Sifrious\Aleph\Envelope\DiscoveryReference;
use Sifrious\Aleph\Envelope\EnvelopeDrafter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Funes\Acceptance\AcceptanceBacklog;
use Sifrious\Funes\Persistence\ObservationStore;

function legacyObservation(string $resource = 'slack:message/1', string $payload = 'hello from before'): string
{
    $envelope = new ObservationEnvelope(
        sourceReference: 'slack:workspace/acme',
        sourceName: 'Acme Workspace',
        resourceReference: $resource,
        observedAt: new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
        payload: $payload,
        provenance: new Provenance('slack', '2.1.0', 'inst-9', new DateTimeImmutable('2026-08-20T10:00:05+00:00')),
        contentType: 'text/plain',
        account: 'U123',
        stream: 'C456',
        providerId: '1692528000.000100',
        occurredAt: new DateTimeImmutable('2026-08-20T09:59:00+00:00'),
    );

    return app(ObservationStore::class)
        ->accept(app(EnvelopeDrafter::class)->draft($envelope))
        ->observation
        ->id;
}

it('sees history written before the acceptance boundary as backlog', function (): void {
    legacyObservation();

    expect(app(AcceptanceBacklog::class)->unkeyedCount())->toBe(1);
});

it('backfills legacy history through the same acceptance path', function (): void {
    $legacyId = legacyObservation();

    $report = app(Backfill::class)->run();

    expect($report->examined)->toBe(1)
        ->and($report->settled())->toBe(1)
        ->and($report->failed)->toBe(0)
        ->and(app(AcceptanceBacklog::class)->unkeyedCount())->toBe(0)
        ->and(DB::table('funes_idempotency_keys')->where('accepted_id', $legacyId)->exists())->toBeTrue();
});

it('does not duplicate history when backfilling', function (): void {
    $legacyId = legacyObservation();

    app(Backfill::class)->run();

    expect(DB::table('funes_observations')->count())->toBe(1)
        ->and(DB::table('funes_observations')->value('id'))->toBe($legacyId);
});

it('is idempotent when the backfill is run repeatedly', function (): void {
    legacyObservation();

    $first = app(Backfill::class)->run();
    $second = app(Backfill::class)->run();
    $third = app(Backfill::class)->run();

    expect($first->settled())->toBe(1)
        ->and($second->examined)->toBe(0)
        ->and($third->examined)->toBe(0)
        ->and(DB::table('funes_observations')->count())->toBe(1);
});

it('records an aleph submission for every backfilled item', function (): void {
    legacyObservation('slack:message/1', 'first');
    legacyObservation('slack:message/2', 'second');

    $report = app(Backfill::class)->run();

    expect($report->examined)->toBe(2)
        ->and($report->settled())->toBe(2)
        ->and(DB::table('aleph_funes_submissions')->count())->toBe(2)
        ->and(DB::table('aleph_funes_submissions')->distinct()->count('idempotency_key'))->toBe(2);
});

it('backfills in bounded batches', function (): void {
    legacyObservation('slack:message/1', 'first');
    legacyObservation('slack:message/2', 'second');
    legacyObservation('slack:message/3', 'third');

    expect(app(Backfill::class)->run(batch: 2)->examined)->toBe(2)
        ->and(app(AcceptanceBacklog::class)->unkeyedCount())->toBe(1);
});

it('keeps occurred, observed and accepted times through a backfill', function (): void {
    legacyObservation();

    app(Backfill::class)->run();

    $row = DB::table('funes_observations')->first();

    expect($row->occurred_at)->not->toBeNull()
        ->and((new DateTimeImmutable((string) $row->occurred_at)) < new DateTimeImmutable((string) $row->observed_at))
        ->toBeTrue()
        ->and((new DateTimeImmutable((string) $row->observed_at)) <= new DateTimeImmutable((string) $row->ingested_at))
        ->toBeTrue();
});

it('carries discovery relationships through a backfill', function (): void {
    $envelope = new ObservationEnvelope(
        sourceReference: 'slack:workspace/acme',
        sourceName: 'Acme Workspace',
        resourceReference: 'slack:message/threaded',
        observedAt: new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
        payload: 'see the thread',
        provenance: new Provenance('slack', '2.1.0', 'inst-9', new DateTimeImmutable('2026-08-20T10:00:05+00:00')),
        discoveries: [
            new DiscoveryReference('slack:file/report.pdf', 'attachment'),
            new DiscoveryReference('slack:message/parent', 'reply_to'),
        ],
    );

    app(ObservationStore::class)->accept(app(EnvelopeDrafter::class)->draft($envelope));

    $report = app(Backfill::class)->run();

    $relationships = DB::table('funes_discoveries')->pluck('relationship')->all();

    expect($report->settled())->toBe(1)
        ->and($relationships)->toEqualCanonicalizing(['attachment', 'reply_to'])
        ->and(DB::table('funes_observations')->count())->toBe(1);
});
