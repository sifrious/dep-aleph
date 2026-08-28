<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Tests\Fixtures\FakeSite;
use Sifrious\Funes\Persistence\ObservationStore;

/**
 * The metadata the pre-A34 writer stored, straight from its ObservationDraft.
 * Parity is judged against this list, not against prose.
 */
const LEGACY_METADATA_KEYS = [
    'http_status',
    'requested_url',
    'final_url',
    'redirect_chain',
    'ingestion_run_id',
    'discovery_origin',
];

function paritySite(): FakeSite
{
    return (new FakeSite)
        ->page('https://ahsd.test/', body: '<html>first<a href="/inside">inside</a><iframe src="https://external.test/embed"></iframe></html>')
        ->page('https://ahsd.test/inside', body: '<html>inside</html>');
}

function parityObservation(): array
{
    config()->set('aleph.web_sources.ahsd', webSource());
    bindSite(paritySite());

    test()->artisan('aleph:crawl', ['source' => 'ahsd'])->assertSuccessful();

    $root = DB::table('aleph_frontier_candidates')->where('canonical_url', 'https://ahsd.test/')->first();
    $observation = app(ObservationStore::class)->get((string) $root->observation_id);

    return [$observation, $root];
}

it('carries every legacy metadata key into the migrated shape', function (): void {
    [$observation] = parityObservation();

    $retrieval = $observation->metadata['extensions'][0];

    expect($retrieval['namespace'])->toBe('web.retrieval')
        ->and(array_keys($retrieval['data']))->toEqualCanonicalizing(LEGACY_METADATA_KEYS);
});

it('preserves every legacy metadata value through the acceptance boundary', function (): void {
    [$observation] = parityObservation();

    expect($observation->metadata['extensions'][0]['data'])->toBe([
        'http_status' => 200,
        'requested_url' => 'https://ahsd.test/',
        'final_url' => 'https://ahsd.test/',
        'redirect_chain' => [],
        'ingestion_run_id' => DB::table('aleph_ingestion_runs')->value('id'),
        'discovery_origin' => 'seed',
    ]);
});

it('adds exactly the documented differences and nothing else', function (): void {
    [$observation] = parityObservation();

    expect(array_keys($observation->metadata))->toEqualCanonicalizing(['aleph', 'extensions'])
        ->and(array_keys($observation->metadata['aleph']))
        ->toEqualCanonicalizing(['envelope_version', 'event_type', 'provenance', 'normalization']);
});

it('keeps payload, references and timing identical to the legacy write', function (): void {
    [$observation] = parityObservation();

    expect($observation->sourceReference)->toBe('web:ahsd')
        ->and($observation->resourceReference)->toBe('https://ahsd.test/')
        ->and($observation->payload)
        ->toBe('<html>first<a href="/inside">inside</a><iframe src="https://external.test/embed"></iframe></html>')
        ->and($observation->payloadHash)->toBe(hash('sha256', $observation->payload))
        ->and($observation->contentType)->toBe('text/html; charset=utf-8')
        ->and($observation->observedAt <= $observation->ingestedAt)->toBeTrue();
});

it('keeps discovery relationships that the legacy write recorded', function (): void {
    [$observation] = parityObservation();

    $relationships = array_map(
        static fn ($discovery): string => $discovery->relationship,
        $observation->discoveries,
    );

    expect($relationships)->toEqualCanonicalizing(['link', 'iframe']);
});

it('keeps counts, dispositions and extraction records at legacy parity', function (): void {
    [, $root] = parityObservation();

    expect(DB::table('funes_observations')->count())->toBe(2)
        ->and($root->observation_disposition)->toBe('first')
        ->and(DB::table('funes_extractions')->count())->toBe(2);
});

it('now backs every crawl observation with a submission and an idempotency key', function (): void {
    parityObservation();

    expect(DB::table('aleph_funes_submissions')->where('status', 'accepted')->count())->toBe(2)
        ->and(DB::table('funes_idempotency_keys')->count())->toBe(2)
        ->and(DB::table('funes_idempotency_keys')->whereNull('accepted_id')->count())->toBe(0);
});
