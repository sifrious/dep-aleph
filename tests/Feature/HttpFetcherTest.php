<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sifrious\Aleph\Web\FetchFailure;
use Sifrious\Aleph\Web\HttpMethod;

beforeEach(function (): void {
    configureHttp();
});

it('preserves requested and final uris across a redirect', function (): void {
    Http::fake([
        'https://ahsd.test/old' => Http::response('', 301, ['Location' => 'https://ahsd.test/new']),
        'https://ahsd.test/new' => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $result = fetchUrl('https://ahsd.test/old');

    expect($result->retrieved())->toBeTrue()
        ->and($result->requestedUrl)->toBe('https://ahsd.test/old')
        ->and($result->finalUrl)->toBe('https://ahsd.test/new')
        ->and($result->status)->toBe(200)
        ->and($result->redirectChain)->toBe(['https://ahsd.test/new'])
        ->and($result->body)->toBe('<html>ok</html>');
});

it('records a not found response as retrieved evidence', function (): void {
    Http::fake(['*' => Http::response('missing', 404, ['Content-Type' => 'text/html'])]);

    $result = fetchUrl('https://ahsd.test/gone');

    expect($result->retrieved())->toBeTrue()
        ->and($result->isOk())->toBeFalse()
        ->and($result->status)->toBe(404)
        ->and($result->failure)->toBeNull();
});

it('records a server error without retrying it', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    $result = fetchUrl('https://ahsd.test/broken');

    expect($result->retrieved())->toBeTrue()
        ->and($result->status)->toBe(500);

    Http::assertSentCount(1);
});

it('classifies a timeout and retries once before giving up', function (): void {
    $attempts = 0;

    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('cURL error 28: Operation timed out after 2000 milliseconds');
    });

    $result = fetchUrl('https://ahsd.test/slow');

    expect($result->retrieved())->toBeFalse()
        ->and($result->failure)->toBe(FetchFailure::Timeout)
        ->and($attempts)->toBe(2);
});

it('does not retry at all when the retry budget is zero', function (): void {
    configureHttp(['retries' => 0]);

    $attempts = 0;

    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('cURL error 7: Failed to connect');
    });

    fetchUrl('https://ahsd.test/down');

    expect($attempts)->toBe(1);
});

it('classifies a connection failure', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    expect(fetchUrl('https://ahsd.test/down')->failure)->toBe(FetchFailure::ConnectionFailed);
});

it('recovers when a retry succeeds', function (): void {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }

        return Http::response('recovered', 200);
    });

    $result = fetchUrl('https://ahsd.test/flaky');

    expect($result->retrieved())->toBeTrue()
        ->and($result->body)->toBe('recovered');
});

it('refuses a response whose declared length exceeds the limit', function (): void {
    Http::fake(['*' => Http::response('short', 200, ['Content-Length' => '99999'])]);

    $result = fetchUrl('https://ahsd.test/huge');

    expect($result->retrieved())->toBeFalse()
        ->and($result->failure)->toBe(FetchFailure::TooLarge)
        ->and($result->failureMessage)->toContain('99999');
});

it('refuses a response whose body exceeds the limit while streaming', function (): void {
    Http::fake(['*' => Http::response(str_repeat('a', 4096), 200)]);

    $result = fetchUrl('https://ahsd.test/big');

    expect($result->retrieved())->toBeFalse()
        ->and($result->failure)->toBe(FetchFailure::TooLarge);
});

it('accepts a body exactly at the limit', function (): void {
    Http::fake(['*' => Http::response(str_repeat('a', 1024), 200)]);

    $result = fetchUrl('https://ahsd.test/edge');

    expect($result->retrieved())->toBeTrue()
        ->and(strlen((string) $result->body))->toBe(1024);
});

it('identifies itself with the configured user agent', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    fetchUrl('https://ahsd.test/');

    Http::assertSent(fn (Request $request): bool => $request->header('User-Agent')[0] === 'AlephCrawler/0.1 (+https://aleph.test)');
});

it('only ever issues get or head requests', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    fetchUrl('https://ahsd.test/a', HttpMethod::Get);
    fetchUrl('https://ahsd.test/b', HttpMethod::Head);

    Http::assertSent(fn (Request $request): bool => in_array($request->method(), ['GET', 'HEAD'], true));

    expect(array_map(
        fn (HttpMethod $method): string => $method->value,
        HttpMethod::cases(),
    ))->toBe(['GET', 'HEAD']);
});

it('does not keep a body for a head request', function (): void {
    Http::fake(['*' => Http::response('ignored', 200)]);

    expect(fetchUrl('https://ahsd.test/', HttpMethod::Head)->body)->toBeNull();
});
