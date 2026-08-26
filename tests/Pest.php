<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Sifrious\Aleph\Tests\Fixtures\FakeSite;
use Sifrious\Aleph\Tests\TestCase;
use Sifrious\Aleph\Web\Fetcher;
use Sifrious\Aleph\Web\FetchRequest;
use Sifrious\Aleph\Web\FetchResult;
use Sifrious\Aleph\Web\HttpMethod;
use Sifrious\Aleph\Web\LinkSource;
use Sifrious\Aleph\Web\UrlCanonicalizer;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

function webSource(array $overrides = []): array
{
    return array_replace([
        'name' => 'Test District',
        'seeds' => ['https://ahsd.test/'],
        'allowed_hosts' => ['ahsd.test', '*.ahsd.test'],
        'excluded' => [],
        'query_parameters' => [],
        'limits' => ['max_pages' => 50, 'max_depth' => 3],
    ], $overrides);
}

function bindSite(FakeSite $site): FakeSite
{
    app()->instance(Fetcher::class, $site);
    app()->instance(LinkSource::class, $site);

    return $site;
}

function configureHttp(array $overrides = []): void
{
    config()->set('aleph.http', array_replace([
        'user_agent' => 'AlephCrawler/0.1 (+https://aleph.test)',
        'connect_timeout' => 1,
        'timeout' => 2,
        'max_response_bytes' => 1024,
        'max_redirects' => 3,
        'delay_ms' => 0,
        'retries' => 1,
        'respect_robots' => false,
    ], $overrides));
}

function fetchUrl(string $url, HttpMethod $method = HttpMethod::Get): FetchResult
{
    $canonical = (new UrlCanonicalizer)->canonicalize($url);

    return app(Fetcher::class)->fetch(new FetchRequest($canonical, $method));
}
