<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Sifrious\Aleph\Tests\Fixtures\HrefLinks;
use Sifrious\Aleph\Web\LinkSource;

function page(string ...$hrefs)
{
    $body = implode('', array_map(fn (string $href): string => "<a href=\"{$href}\">x</a>", $hrefs));

    return Http::response("<html><body>{$body}</body></html>", 200, ['Content-Type' => 'text/html']);
}

function crawlTotals(array $options = []): array
{
    test()->artisan('aleph:crawl', array_replace(['source' => 'ahsd'], $options))->assertSuccessful();

    $run = DB::table('aleph_crawl_runs')->orderByDesc('id')->first();

    return json_decode((string) $run->totals, true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    configureHttp(['respect_robots' => true, 'max_response_bytes' => 65536]);

    config()->set('aleph.web_sources.ahsd', webSource([
        'seeds' => ['https://ahsd.test/'],
        'limits' => ['max_pages' => 50, 'max_depth' => 2],
    ]));

    app()->instance(LinkSource::class, new HrefLinks);
});

it('crawls a small site over http and reports what happened', function (): void {
    Http::fake([
        'https://ahsd.test/robots.txt' => Http::response("User-agent: *\nDisallow: /private\n", 200),
        'https://ahsd.test/' => page('/news', '/private/secret', '/broken', '/gone', 'https://facebook.test/x'),
        'https://ahsd.test/news' => page('/news/2026'),
        'https://ahsd.test/news/2026' => page(),
        'https://ahsd.test/gone' => Http::response('missing', 404),
        'https://ahsd.test/broken' => fn () => throw new ConnectionException('cURL error 7: Failed to connect'),
    ]);

    $totals = crawlTotals();

    expect($totals)->toMatchArray([
        'fetched' => 4,
        'unsuccessful' => 1,
        'failed' => 2,
        'stopped_by' => 'frontier_exhausted',
        'remaining' => 0,
    ]);

    expect($totals['skipped_by_reason'])->toBe(['external_host' => 1]);
});

it('records a robots refusal as failure evidence without stopping the crawl', function (): void {
    Http::fake([
        'https://ahsd.test/robots.txt' => Http::response("User-agent: *\nDisallow: /private\n", 200),
        'https://ahsd.test/' => page('/private/secret', '/news'),
        'https://ahsd.test/news' => page(),
    ]);

    crawlTotals();

    $refused = DB::table('aleph_frontier_candidates')
        ->where('canonical_url', 'https://ahsd.test/private/secret')
        ->first();

    expect($refused->state)->toBe('failed')
        ->and($refused->failure)->toBe('robots_disallowed');

    expect(DB::table('aleph_frontier_candidates')->where('state', 'fetched')->count())->toBe(2);
});

it('never issues a request outside the allowed hosts', function (): void {
    Http::fake([
        'https://ahsd.test/robots.txt' => Http::response('', 404),
        'https://ahsd.test/' => page('https://facebook.test/ahsd', 'https://ms.ahsd.test/'),
        'https://ms.ahsd.test/robots.txt' => Http::response('', 404),
        'https://ms.ahsd.test/' => page(),
    ]);

    crawlTotals();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'facebook.test'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ms.ahsd.test/');
});

it('stops requesting once the page limit is reached', function (): void {
    Http::fake([
        'https://ahsd.test/robots.txt' => Http::response('', 404),
        'https://ahsd.test/' => page('/a', '/b', '/c', '/d'),
        '*' => page(),
    ]);

    $totals = crawlTotals(['--max-pages' => '2']);

    expect($totals)->toMatchArray(['fetched' => 2, 'stopped_by' => 'page_limit']);

    $pageRequests = collect(Http::recorded())
        ->reject(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/robots.txt'))
        ->count();

    expect($pageRequests)->toBe(2);
});

it('abandons an oversized response without failing the run', function (): void {
    configureHttp(['respect_robots' => false, 'max_response_bytes' => 1024]);

    Http::fake([
        'https://ahsd.test/' => page('/huge', '/small'),
        'https://ahsd.test/huge' => Http::response(str_repeat('x', 5000), 200),
        'https://ahsd.test/small' => page(),
    ]);

    $totals = crawlTotals();

    expect($totals)->toMatchArray(['fetched' => 2, 'failed' => 1, 'stopped_by' => 'frontier_exhausted']);

    expect(DB::table('aleph_frontier_candidates')->where('canonical_url', 'https://ahsd.test/huge')->value('failure'))
        ->toBe('too_large');
});
