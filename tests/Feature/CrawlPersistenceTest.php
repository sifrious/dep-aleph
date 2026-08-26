<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Tests\Fixtures\FailingObservationStore;
use Sifrious\Aleph\Tests\Fixtures\FakeSite;
use Sifrious\Funes\Persistence\ObservationStore;

function persistenceSite(string $root = '<html>first</html>'): FakeSite
{
    return (new FakeSite)
        ->page('https://ahsd.test/', ['/inside', 'https://external.test/embed'], body: $root)
        ->page('https://ahsd.test/inside', body: '<html>inside</html>');
}

beforeEach(function (): void {
    config()->set('aleph.web_sources.ahsd', webSource());
});

it('persists retrieved content and discovery provenance exclusively through Funes', function (): void {
    $site = persistenceSite();
    bindSite($site);

    $this->artisan('aleph:crawl', ['source' => 'ahsd'])->assertSuccessful();

    $root = DB::table('aleph_frontier_candidates')->where('canonical_url', 'https://ahsd.test/')->first();
    $external = DB::table('aleph_frontier_candidates')->where('canonical_url', 'https://external.test/embed')->first();
    $store = app(ObservationStore::class);
    $observation = $store->get((string) $root->observation_id);
    $externalProvenance = $store->discoveriesTo('web:ahsd', 'https://external.test/embed');

    expect(DB::table('funes_observations')->count())->toBe(2)
        ->and($root->observation_disposition)->toBe('first')
        ->and($observation?->payload)->toBe('<html>first</html>')
        ->and($observation?->metadata)->toMatchArray([
            'http_status' => 200,
            'requested_url' => 'https://ahsd.test/',
            'final_url' => 'https://ahsd.test/',
            'discovery_origin' => 'seed',
        ])
        ->and($observation?->discoveries)->toHaveCount(2)
        ->and($externalProvenance)->toHaveCount(1)
        ->and($externalProvenance[0]->parentResourceReference)->toBe('https://ahsd.test/')
        ->and($external->state)->toBe('skipped')
        ->and(DB::table('funes_resources')->where('canonical_reference', 'https://external.test/embed')->exists())->toBeTrue()
        ->and($site->requested)->not->toContain('https://external.test/embed');
});

it('records unchanged and changed Funes dispositions across fresh bounded runs', function (): void {
    bindSite(persistenceSite());
    $this->artisan('aleph:crawl', ['source' => 'ahsd'])->assertSuccessful();

    bindSite(persistenceSite());
    $this->artisan('aleph:crawl', ['source' => 'ahsd', '--fresh' => true])->assertSuccessful();

    expect(DB::table('funes_observations')->count())->toBe(2)
        ->and(DB::table('aleph_frontier_candidates')->where('observation_disposition', 'unchanged')->count())->toBe(2);

    bindSite(persistenceSite('<html>changed</html>'));
    $this->artisan('aleph:crawl', ['source' => 'ahsd', '--fresh' => true])->assertSuccessful();

    expect(DB::table('funes_observations')->count())->toBe(3)
        ->and(DB::table('aleph_frontier_candidates')->where('observation_disposition', 'changed')->count())->toBe(1);
});

it('interrupts and resumes the same run when required Funes acceptance fails', function (): void {
    $site = persistenceSite();
    bindSite($site);
    $store = app(ObservationStore::class);
    app()->instance(ObservationStore::class, new FailingObservationStore($store));

    $this->artisan('aleph:crawl', ['source' => 'ahsd'])
        ->expectsOutputToContain('Funes acceptance failed')
        ->assertFailed();

    $run = DB::table('aleph_ingestion_runs')->first();

    expect($run->status)->toBe('interrupted')
        ->and(DB::table('aleph_frontier_candidates')->where('state', 'fetching')->count())->toBe(1)
        ->and(DB::table('funes_observations')->count())->toBe(0);

    $this->artisan('aleph:crawl', ['source' => 'ahsd'])
        ->expectsOutputToContain("Resuming unfinished run {$run->id}")
        ->assertSuccessful();

    expect(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and(DB::table('aleph_ingestion_runs')->value('status'))->toBe('completed')
        ->and(DB::table('aleph_frontier_candidates')->where('state', 'fetched')->count())->toBe(2)
        ->and(DB::table('funes_observations')->count())->toBe(2);
});
