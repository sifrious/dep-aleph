<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Tests\Fixtures\FakeSite;

function totalsForLatestRun(): array
{
    $run = DB::table('aleph_ingestion_runs')->orderByDesc('id')->first();

    expect($run)->not->toBeNull();

    return json_decode((string) $run->totals, true, 512, JSON_THROW_ON_ERROR);
}

it('completes a single page fake crawl with a visible run summary', function (): void {
    config()->set('aleph.web_sources.test', webSource());
    bindSite((new FakeSite)->page('https://ahsd.test/'));

    $this->artisan('aleph:crawl', ['source' => 'test'])
        ->expectsOutputToContain('Fetched')
        ->assertSuccessful();

    $run = DB::table('aleph_ingestion_runs')->first();

    expect(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and($run->capability)->toBe('web.crawl')
        ->and($run->status)->toBe('completed')
        ->and($run->finished_at)->not->toBeNull();

    expect(totalsForLatestRun())
        ->toMatchArray([
            'fetched' => 1,
            'failed' => 0,
            'skipped' => 0,
            'remaining' => 0,
            'stopped_by' => 'frontier_exhausted',
        ]);
});

it('fails cleanly for an unknown source', function (): void {
    config()->set('aleph.web_sources', ['test' => webSource()]);

    $this->artisan('aleph:crawl', ['source' => 'nope'])
        ->expectsOutputToContain('Unknown web source [nope]')
        ->assertFailed();

    expect(DB::table('aleph_ingestion_runs')->count())->toBe(0);
});

it('rejects a non numeric limit override', function (): void {
    config()->set('aleph.web_sources.test', webSource());
    bindSite(new FakeSite);

    $this->artisan('aleph:crawl', ['source' => 'test', '--max-pages' => 'lots'])
        ->expectsOutputToContain('must be a non-negative integer')
        ->assertFailed();
});

it('stops at a page limit supplied on the command line', function (): void {
    config()->set('aleph.web_sources.test', webSource());

    bindSite((new FakeSite)
        ->page('https://ahsd.test/', ['/a', '/b', '/c'])
        ->page('https://ahsd.test/a')
        ->page('https://ahsd.test/b')
        ->page('https://ahsd.test/c'));

    $this->artisan('aleph:crawl', ['source' => 'test', '--max-pages' => '2'])
        ->assertSuccessful();

    expect(totalsForLatestRun())
        ->toMatchArray([
            'fetched' => 2,
            'remaining' => 2,
            'stopped_by' => 'page_limit',
        ]);
});

it('restricts a crawl to hosts named on the command line', function (): void {
    config()->set('aleph.web_sources.test', webSource([
        'seeds' => ['https://ahsd.test/', 'https://hs.ahsd.test/'],
    ]));

    bindSite((new FakeSite)
        ->page('https://ahsd.test/')
        ->page('https://hs.ahsd.test/'));

    $this->artisan('aleph:crawl', ['source' => 'test', '--host' => ['hs.ahsd.test']])
        ->assertSuccessful();

    expect(totalsForLatestRun())->toMatchArray(['fetched' => 1]);

    expect(DB::table('aleph_frontier_candidates')->where('state', 'fetched')->value('canonical_url'))
        ->toBe('https://hs.ahsd.test/');
});

it('resumes an unfinished run with its original parameters and starts a new one with fresh', function (): void {
    config()->set('aleph.web_sources.test', webSource());

    bindSite((new FakeSite)
        ->page('https://ahsd.test/', ['/a', '/b'])
        ->page('https://ahsd.test/a')
        ->page('https://ahsd.test/b'));

    $this->artisan('aleph:crawl', ['source' => 'test', '--max-pages' => '1'])->assertSuccessful();

    $first = DB::table('aleph_ingestion_runs')->first();

    DB::table('aleph_ingestion_runs')->where('id', $first->id)->update(['status' => 'interrupted']);

    $this->artisan('aleph:crawl', ['source' => 'test'])
        ->expectsOutputToContain("Resuming unfinished run {$first->id}")
        ->assertSuccessful();

    expect(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and(totalsForLatestRun())->toMatchArray(['fetched' => 1, 'remaining' => 2, 'stopped_by' => 'page_limit']);

    $this->artisan('aleph:crawl', ['source' => 'test', '--fresh'])->assertSuccessful();

    expect(DB::table('aleph_ingestion_runs')->count())->toBe(2);
});
