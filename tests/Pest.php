<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Sifrious\Aleph\Tests\Fixtures\FakeSite;
use Sifrious\Aleph\Tests\TestCase;
use Sifrious\Aleph\Web\Fetcher;
use Sifrious\Aleph\Web\FetchRequest;
use Sifrious\Aleph\Web\FetchResult;
use Sifrious\Aleph\Web\HttpMethod;
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

function pdfWithEmbeddedText(string $text): string
{
    $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    $stream = "BT\n/F1 18 Tf\n72 720 Td\n({$escaped}) Tj\nET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $number = $index + 1;
        $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
    }

    return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}
