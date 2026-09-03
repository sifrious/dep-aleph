<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\Configuration\WebCrawlConnector;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Tests\Fixtures\FakeSite;

it('follows the documented first run from source configuration to inspection', function (): void {
    app(ConnectorRegistry::class)->register(app(WebCrawlConnector::class));

    $configured = Artisan::call('aleph:source:configure', [
        'connector' => 'web-crawl',
        'source-key' => 'first-run',
        'name' => 'First run',
        '--value' => [
            'seeds=["https://first-run.test/"]',
            'allowed_hosts=["first-run.test"]',
        ],
        '--json' => true,
    ]);
    $source = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    config()->set('aleph.web_sources.first-run', webSource([
        'name' => 'First run',
        'seeds' => ['https://first-run.test/'],
        'allowed_hosts' => ['first-run.test'],
    ]));
    bindSite((new FakeSite)->page('https://first-run.test/'));
    $crawled = Artisan::call('aleph:crawl', ['source' => 'first-run']);
    $inspected = Artisan::call('aleph:source:show', [
        'installation' => $source['installation']['id'],
        '--json' => true,
    ]);
    $state = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($configured)->toBe(0)
        ->and($crawled)->toBe(0)
        ->and($inspected)->toBe(0)
        ->and($source['source_reference'])->toBe('web:first-run')
        ->and($state['installation']['id'])->toBe($source['installation']['id'])
        ->and(DB::table('funes_observations')->count())->toBe(2);
});
