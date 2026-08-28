<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Tests\Fixtures\FakeSite;

function ahsdFixture(): FakeSite
{
    return (new FakeSite)
        ->page('https://ahsd.test/', [
            '/news',
            '/news#top',
            '/news?utm_source=newsletter',
            'https://ms.ahsd.test/',
            'https://facebook.com/ahsd',
            'mailto:info@ahsd.test',
            '/login',
        ])
        ->page('https://hs.ahsd.test/', ['/athletics'])
        ->page('https://ms.ahsd.test/', ['/bell'])
        ->page('https://ahsd.test/news', ['/news/2026'])
        ->page('https://ahsd.test/news/2026', ['/news/2026/deep'])
        ->page('https://ahsd.test/news/2026/deep')
        ->page('https://hs.ahsd.test/athletics')
        ->page('https://ms.ahsd.test/bell');
}

function configureAhsd(array $overrides = []): void
{
    config()->set('aleph.web_sources.ahsd', webSource(array_replace([
        'seeds' => ['https://ahsd.test/', 'https://hs.ahsd.test/'],
        'excluded' => ['*/login*'],
        'limits' => ['max_pages' => 50, 'max_depth' => 2],
    ], $overrides)));
}

function runAhsdCrawl(FakeSite $site, array $options = []): array
{
    bindSite($site);

    test()->artisan('aleph:crawl', array_replace(['source' => 'ahsd'], $options))->assertSuccessful();

    $run = DB::table('aleph_ingestion_runs')->orderByDesc('id')->first();

    return json_decode((string) $run->stats, true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    configureAhsd();
});

it('produces exact deterministic stats for a bounded multi seed crawl', function (): void {
    expect(runAhsdCrawl(ahsdFixture()))->toBe([
        'fetched' => 7,
        'unsuccessful' => 0,
        'failed' => 0,
        'skipped' => 3,
        'skipped_by_reason' => [
            'depth_limit' => 1,
            'excluded' => 1,
            'external_host' => 1,
        ],
        'duplicates' => 2,
        'unresolvable' => 1,
        'discovered' => 13,
        'remaining' => 0,
        'stopped_by' => 'frontier_exhausted',
    ]);
});

it('admits newly discovered subdomains of an allowed host', function (): void {
    runAhsdCrawl(ahsdFixture());

    $fetched = DB::table('aleph_frontier_candidates')
        ->where('state', 'fetched')
        ->orderBy('id')
        ->pluck('canonical_url')
        ->all();

    expect($fetched)->toContain('https://ms.ahsd.test/', 'https://ms.ahsd.test/bell');

    expect(DB::table('aleph_frontier_candidates')->where('canonical_url', 'https://ms.ahsd.test/')->value('origin'))
        ->toBe('link');
});

it('records external urls without ever requesting them', function (): void {
    $site = ahsdFixture();

    runAhsdCrawl($site);

    $external = DB::table('aleph_frontier_candidates')
        ->where('canonical_url', 'https://facebook.com/ahsd')
        ->first();

    expect($external)->not->toBeNull()
        ->and($external->state)->toBe('skipped')
        ->and($external->skip_reason)->toBe('external_host')
        ->and($external->parent_id)->not->toBeNull();

    expect($site->requested)->not->toContain('https://facebook.com/ahsd');
});

it('fetches each canonical url at most once despite query and fragment variants', function (): void {
    $site = ahsdFixture();

    runAhsdCrawl($site);

    expect(array_count_values($site->requested)['https://ahsd.test/news'])->toBe(1);

    expect(DB::table('aleph_frontier_candidates')->where('canonical_url', 'https://ahsd.test/news')->count())
        ->toBe(1);

    expect(count($site->requested))->toBe(count(array_unique($site->requested)));
});

it('stops at the depth limit and records what it refused to enqueue', function (): void {
    $site = ahsdFixture();

    runAhsdCrawl($site);

    $deep = DB::table('aleph_frontier_candidates')
        ->where('canonical_url', 'https://ahsd.test/news/2026/deep')
        ->first();

    expect($deep->state)->toBe('skipped')
        ->and($deep->skip_reason)->toBe('depth_limit')
        ->and((int) $deep->depth)->toBe(3);

    expect($site->requested)->not->toContain('https://ahsd.test/news/2026/deep');
});

it('terminates on the page limit leaving the rest of the frontier pending', function (): void {
    $stats = runAhsdCrawl(ahsdFixture(), ['--max-pages' => '3']);

    expect($stats)->toMatchArray(['fetched' => 3, 'stopped_by' => 'page_limit'])
        ->and($stats['remaining'])->toBeGreaterThan(0);
});

it('keeps crawling after a page fails', function (): void {
    $stats = runAhsdCrawl(ahsdFixture()->fails('https://ahsd.test/news'));

    expect($stats)->toMatchArray(['failed' => 1, 'stopped_by' => 'frontier_exhausted'])
        ->and($stats['fetched'])->toBe(5);

    expect(DB::table('aleph_frontier_candidates')->where('canonical_url', 'https://ahsd.test/news')->first())
        ->state->toBe('failed')
        ->failure->toBe('connection_failed');
});

it('records a not found response as fetched evidence rather than a failure', function (): void {
    $site = ahsdFixture();

    runAhsdCrawl($site);

    config()->set('aleph.web_sources.ahsd.seeds', ['https://ahsd.test/missing']);

    $stats = runAhsdCrawl($site, ['--fresh' => true]);

    expect($stats)->toMatchArray(['fetched' => 1, 'failed' => 0]);

    expect((int) DB::table('aleph_frontier_candidates')
        ->where('canonical_url', 'https://ahsd.test/missing')
        ->value('http_status'))->toBe(404);
});

it('produces identical stats across repeated runs of the same fixture', function (): void {
    $first = runAhsdCrawl(ahsdFixture());
    $second = runAhsdCrawl(ahsdFixture(), ['--fresh' => true]);

    expect($second)->toBe($first);
});

it('follows a redirect and keeps requested and final urls distinct', function (): void {
    $site = ahsdFixture()->redirect('https://ahsd.test/news', 'https://ahsd.test/news/2026');

    runAhsdCrawl($site);

    $row = DB::table('aleph_frontier_candidates')
        ->where('canonical_url', 'https://ahsd.test/news')
        ->first();

    expect($row->state)->toBe('fetched')
        ->and($row->final_url)->toBe('https://ahsd.test/news/2026');
});
