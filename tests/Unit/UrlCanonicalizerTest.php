<?php

declare(strict_types=1);

use Sifrious\Aleph\Web\CanonicalUrl;
use Sifrious\Aleph\Web\UrlCanonicalizer;

function canonicalizer(array $allowed = []): UrlCanonicalizer
{
    return new UrlCanonicalizer($allowed);
}

function base(string $url): CanonicalUrl
{
    $canonical = canonicalizer()->canonicalize($url);

    expect($canonical)->not->toBeNull();

    return $canonical;
}

it('canonicalizes absolute urls', function (string $input, string $expected): void {
    expect((string) canonicalizer()->canonicalize($input))->toBe($expected);
})->with([
    'lowercases scheme and host' => ['HTTP://WWW.AHSD.ORG/About', 'http://www.ahsd.org/About'],
    'adds a root path' => ['https://ahsd.org', 'https://ahsd.org/'],
    'drops the fragment' => ['https://ahsd.org/calendar#october', 'https://ahsd.org/calendar'],
    'drops a bare fragment marker' => ['https://ahsd.org/calendar#', 'https://ahsd.org/calendar'],
    'drops the default http port' => ['http://ahsd.org:80/a', 'http://ahsd.org/a'],
    'drops the default https port' => ['https://ahsd.org:443/a', 'https://ahsd.org/a'],
    'keeps a non-default port' => ['https://ahsd.org:8443/a', 'https://ahsd.org:8443/a'],
    'strips embedded credentials' => ['https://user:pass@ahsd.org/a', 'https://ahsd.org/a'],
    'strips a trailing dot from the host' => ['https://ahsd.org./a', 'https://ahsd.org/a'],
    'resolves dot segments' => ['https://ahsd.org/a/b/../c', 'https://ahsd.org/a/c'],
    'resolves leading dot segments' => ['https://ahsd.org/../../a', 'https://ahsd.org/a'],
    'preserves a trailing slash' => ['https://ahsd.org/a/', 'https://ahsd.org/a/'],
]);

it('rejects unusable references', function (string $input): void {
    expect(canonicalizer()->canonicalize($input))->toBeNull();
})->with([
    'mailto' => ['mailto:info@ahsd.org'],
    'telephone' => ['tel:+15705551212'],
    'javascript' => ['javascript:void(0)'],
    'data' => ['data:text/plain;base64,QQ=='],
    'ftp' => ['ftp://ahsd.org/file'],
    'empty' => [''],
    'whitespace only' => ['   '],
    'relative without a base' => ['/calendar'],
    'missing host' => ['https:///calendar'],
    'non numeric port' => ['https://ahsd.org:port/a'],
]);

it('resolves references against a base', function (string $reference, string $expected): void {
    expect((string) canonicalizer()->canonicalize($reference, base('https://hs.ahsd.org/athletics/index.html')))
        ->toBe($expected);
})->with([
    'absolute path' => ['/calendar', 'https://hs.ahsd.org/calendar'],
    'relative path' => ['schedule', 'https://hs.ahsd.org/athletics/schedule'],
    'parent relative path' => ['../news', 'https://hs.ahsd.org/news'],
    'current directory' => ['./teams', 'https://hs.ahsd.org/athletics/teams'],
    'protocol relative' => ['//ms.ahsd.org/bell', 'https://ms.ahsd.org/bell'],
    'bare fragment resolves to the base' => ['#roster', 'https://hs.ahsd.org/athletics/index.html'],
    'absolute reference wins' => ['https://cse.ahsd.org/', 'https://cse.ahsd.org/'],
]);

it('drops every query parameter when no allowlist is configured', function (): void {
    expect((string) canonicalizer()->canonicalize('https://ahsd.org/calendar?date=2026-10-01&utm_source=x'))
        ->toBe('https://ahsd.org/calendar');
});

it('keeps only allowlisted query parameters', function (): void {
    expect((string) canonicalizer(['page'])->canonicalize('https://ahsd.org/news?utm_source=x&page=2&fbclid=y'))
        ->toBe('https://ahsd.org/news?page=2');
});

it('orders retained query parameters deterministically', function (): void {
    $canonicalizer = canonicalizer(['page', 'section']);

    $first = (string) $canonicalizer->canonicalize('https://ahsd.org/news?section=sports&page=2');
    $second = (string) $canonicalizer->canonicalize('https://ahsd.org/news?page=2&section=sports');

    expect($first)->toBe($second)
        ->and($first)->toBe('https://ahsd.org/news?page=2&section=sports');
});

it('collapses unbounded query variants of the same page to one canonical url', function (): void {
    $canonicalizer = canonicalizer();

    $variants = [
        'https://ahsd.org/calendar?date=2026-10-01',
        'https://ahsd.org/calendar?date=2026-10-02',
        'https://ahsd.org/calendar?date=2026-10-03&view=month',
        'https://ahsd.org/calendar#week-2',
    ];

    $canonical = array_map(
        fn (string $url): string => (string) $canonicalizer->canonicalize($url),
        $variants,
    );

    expect(array_unique($canonical))->toHaveCount(1);
});

it('exposes a stable hash for canonical identity', function (): void {
    $url = canonicalizer()->canonicalize('https://ahsd.org/a');

    expect($url->hash())->toBe(hash('sha256', 'https://ahsd.org/a'))
        ->and($url->hash())->toHaveLength(64);
});
