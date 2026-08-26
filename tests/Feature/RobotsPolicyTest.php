<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sifrious\Aleph\Tests\Fixtures\FakeClock;
use Sifrious\Aleph\Web\Clock;
use Sifrious\Aleph\Web\FetchFailure;

beforeEach(function (): void {
    configureHttp(['respect_robots' => true]);
});

it('refuses a path the host disallows', function (): void {
    Http::fake([
        'https://ahsd.test/robots.txt' => Http::response("User-agent: *\nDisallow: /private\n", 200),
        '*' => Http::response('ok', 200),
    ]);

    $result = fetchUrl('https://ahsd.test/private/files');

    expect($result->retrieved())->toBeFalse()
        ->and($result->failure)->toBe(FetchFailure::RobotsDisallowed);
});

it('retrieves a path the host allows', function (): void {
    Http::fake([
        'https://ahsd.test/robots.txt' => Http::response("User-agent: *\nDisallow: /private\n", 200),
        '*' => Http::response('ok', 200),
    ]);

    expect(fetchUrl('https://ahsd.test/news')->status)->toBe(200);
});

it('treats a missing robots file as permission to crawl', function (): void {
    Http::fake([
        'https://ahsd.test/robots.txt' => Http::response('', 404),
        '*' => Http::response('ok', 200),
    ]);

    expect(fetchUrl('https://ahsd.test/anything')->status)->toBe(200);
});

it('refuses to crawl a host whose robots file returns a server error', function (): void {
    Http::fake([
        'https://ahsd.test/robots.txt' => Http::response('', 503),
        '*' => Http::response('ok', 200),
    ]);

    expect(fetchUrl('https://ahsd.test/anything')->failure)->toBe(FetchFailure::RobotsDisallowed);
});

it('refuses to crawl a host whose robots file is unreachable', function (): void {
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/robots.txt')) {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }

        return Http::response('ok', 200);
    });

    expect(fetchUrl('https://ahsd.test/anything')->failure)->toBe(FetchFailure::RobotsDisallowed);
});

it('reads the robots file once per host', function (): void {
    $robotsRequests = 0;

    Http::fake(function (Request $request) use (&$robotsRequests) {
        if (str_ends_with($request->url(), '/robots.txt')) {
            $robotsRequests++;

            return Http::response("User-agent: *\nDisallow: /private\n", 200);
        }

        return Http::response('ok', 200);
    });

    fetchUrl('https://ahsd.test/a');
    fetchUrl('https://ahsd.test/b');
    fetchUrl('https://hs.ahsd.test/c');

    expect($robotsRequests)->toBe(2);
});

it('raises the host delay to the advertised crawl delay', function (): void {
    $clock = new FakeClock;
    app()->instance(Clock::class, $clock);

    Http::fake([
        'https://ahsd.test/robots.txt' => Http::response("User-agent: *\nCrawl-delay: 5\n", 200),
        '*' => Http::response('ok', 200),
    ]);

    fetchUrl('https://ahsd.test/a');
    fetchUrl('https://ahsd.test/b');

    expect($clock->slept)->toBe([5.0]);
});

it('ignores the robots policy entirely when it is switched off', function (): void {
    configureHttp(['respect_robots' => false]);

    Http::fake([
        'https://ahsd.test/robots.txt' => Http::response("User-agent: *\nDisallow: /\n", 200),
        '*' => Http::response('ok', 200),
    ]);

    expect(fetchUrl('https://ahsd.test/private')->status)->toBe(200);
});
