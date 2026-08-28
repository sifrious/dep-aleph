<?php

declare(strict_types=1);

use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Inventory\CsvInventory;
use Sifrious\Aleph\Inventory\Inventory;
use Sifrious\Aleph\Inventory\InventoryReader;
use Sifrious\Aleph\Inventory\InventoryResource;
use Sifrious\Aleph\Inventory\JsonInventory;
use Sifrious\Aleph\Tests\Fixtures\FakeSite;
use Sifrious\Aleph\Web\CrawlParameters;
use Sifrious\Aleph\Web\FetchFailure;
use Sifrious\Aleph\Web\WebSources;

const CALENDAR = 'https://wav.ahsd.test/our_school/calendar';
const ARTIFACT = 'https://drive.google.test/file/d/1gXu9XTbR2TTbYC-bHrUxJHboIZHS1TDw/preview';
const SUPPLIES = 'https://wav.ahsd.test/userfiles/supply-list.pdf';
const DELIVERED = 'https://cdn.sharpschool.test/userfiles/supply-list.pdf';

function district(): FakeSite
{
    $home = sprintf(
        '<html><body><a href="%s">Calendar</a><a href="%s">Supplies</a><a href="/login">Login</a>'
        .'<a href="/gone">Gone</a><a href="/down">Down</a></body></html>',
        CALENDAR,
        SUPPLIES,
    );

    return (new FakeSite)
        ->page('https://wav.ahsd.test/', body: $home)
        ->page(CALENDAR, body: sprintf('<html><body><iframe src="%s"></iframe></body></html>', ARTIFACT))
        ->page(DELIVERED, contentType: 'application/pdf', body: pdfWithEmbeddedText('Grade 5 Supply List'))
        ->redirect(SUPPLIES, DELIVERED)
        ->fails('https://wav.ahsd.test/down', FetchFailure::Timeout);
}

function inventory(?string $runId = null): Inventory
{
    $runs = app(IngestionRuns::class);
    $source = app(WebSources::class)->get('waverly');
    $run = $runId === null ? $runs->latest($source->reference(), Capability::WebCrawl) : $runs->find($runId);
    $source = CrawlParameters::fromRun($run)->applyTo($source);

    return app(InventoryReader::class)->read($source, $run);
}

/**
 * @return array<string, string|int|bool|null>
 */
function resource(Inventory $inventory, string $canonicalUrl): array
{
    foreach ($inventory->resources as $candidate) {
        if ($candidate->canonicalUrl === $canonicalUrl) {
            return $candidate->toArray();
        }
    }

    return [];
}

beforeEach(function (): void {
    config()->set('aleph.web_sources.waverly', webSource([
        'name' => 'Waverly Elementary',
        'seeds' => ['https://wav.ahsd.test/'],
        'allowed_hosts' => ['wav.ahsd.test'],
        'excluded' => ['*/login*'],
        'calendar_signals' => ['*calendar', '*calendar/*'],
        'limits' => ['max_pages' => 20, 'max_depth' => 2],
    ]));
    bindSite(district());
});

it('records the crawl bounds the run actually applied', function (): void {
    $this->artisan('aleph:crawl', ['source' => 'waverly', '--max-pages' => '10'])->assertSuccessful();

    $bounds = inventory()->bounds->toArray();

    expect($bounds)->toMatchArray([
        'source_reference' => 'web:waverly',
        'source_name' => 'Waverly Elementary',
        'capability' => 'web.crawl',
        'status' => 'completed',
        'max_pages' => 10,
        'max_depth' => 2,
        'allowed_hosts' => ['wav.ahsd.test'],
        'excluded' => ['*/login*'],
        'query_parameters' => [],
        'calendar_signals' => ['*calendar', '*calendar/*'],
        'error' => null,
    ])
        ->and($bounds['seeds'])->toBe(['https://wav.ahsd.test/'])
        ->and($bounds['stats'])->toMatchArray(['stopped_by' => 'frontier_exhausted'])
        ->and($bounds['started_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($bounds['finished_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('leads a calendar page to its external artifact through recorded provenance', function (): void {
    $this->artisan('aleph:crawl', ['source' => 'waverly'])->assertSuccessful();

    $inventory = inventory();

    expect(resource($inventory, CALENDAR))->toMatchArray([
        'state' => 'fetched',
        'http_status' => 200,
        'external' => false,
        'calendar_like' => true,
        'calendar_signal' => 'path',
        'extractor' => 'aleph.html',
        'extraction_version' => '1',
        'extraction_status' => 'succeeded',
        'freshness' => 'current',
    ]);

    expect(resource($inventory, ARTIFACT))->toMatchArray([
        'state' => 'skipped',
        'skip_reason' => 'external_host',
        'origin' => 'iframe',
        'parent_canonical_url' => CALENDAR,
        'external' => true,
        'calendar_like' => true,
        'calendar_signal' => 'embedded_in_calendar',
        'observation_id' => null,
        'freshness' => 'unobserved',
    ]);
});

it('exposes http results, hashes, and observation times for retrieved resources', function (): void {
    $this->artisan('aleph:crawl', ['source' => 'waverly'])->assertSuccessful();

    $inventory = inventory();
    $supplies = resource($inventory, SUPPLIES);

    expect($supplies)->toMatchArray([
        'final_url' => DELIVERED,
        'http_status' => 200,
        'content_type' => 'application/pdf',
        'observation_disposition' => 'first',
        'extractor' => 'aleph.pdf',
        'extraction_status' => 'succeeded',
        'extraction_error' => null,
        'freshness' => 'current',
    ])
        ->and($supplies['payload_hash'])->toBe(hash('sha256', pdfWithEmbeddedText('Grade 5 Supply List')))
        ->and($supplies['byte_size'])->toBe(strlen(pdfWithEmbeddedText('Grade 5 Supply List')))
        ->and($supplies['observed_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($supplies['ingested_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($supplies['last_observed_at'])->toBe($supplies['observed_at']);
});

it('separates unsuccessful responses, transport failures, and excluded candidates', function (): void {
    $this->artisan('aleph:crawl', ['source' => 'waverly'])->assertSuccessful();

    $inventory = inventory();

    expect(resource($inventory, 'https://wav.ahsd.test/gone'))->toMatchArray([
        'state' => 'fetched',
        'http_status' => 404,
        'failure' => null,
        'extraction_status' => 'succeeded',
    ]);

    expect(resource($inventory, 'https://wav.ahsd.test/down'))->toMatchArray([
        'state' => 'failed',
        'http_status' => null,
        'failure' => 'timeout',
        'failure_message' => 'Fake transport failure.',
        'observation_id' => null,
        'freshness' => 'unobserved',
    ]);

    expect(resource($inventory, 'https://wav.ahsd.test/login'))->toMatchArray([
        'state' => 'skipped',
        'skip_reason' => 'excluded',
        'external' => false,
    ]);

    expect($inventory->totals())->toMatchArray([
        'unsuccessful' => 1,
        'failures' => 1,
        'external' => 1,
        'external_embeds' => 1,
        'calendar_like' => 2,
        'extraction_errors' => 0,
    ]);
});

it('reports a candidate as stale when its only observation predates the run', function (): void {
    $this->artisan('aleph:crawl', ['source' => 'waverly'])->assertSuccessful();

    $first = inventory();

    $this->artisan('aleph:crawl', ['source' => 'waverly', '--fresh' => true, '--max-pages' => '1'])
        ->assertSuccessful();

    $second = inventory();
    $stale = resource($second, CALENDAR);

    expect($second->bounds->runId)->not->toBe($first->bounds->runId)
        ->and($stale)->toMatchArray([
            'state' => 'pending',
            'http_status' => null,
            'observation_disposition' => null,
            'extractor' => null,
            'freshness' => 'stale',
        ])
        ->and($stale['observation_id'])->toBe(resource($first, CALENDAR)['observation_id'])
        ->and($stale['payload_hash'])->toBe(resource($first, CALENDAR)['payload_hash'])
        ->and($stale['observed_at'])->toBeNull()
        ->and($stale['last_observed_at'])->toBe(resource($first, CALENDAR)['observed_at'])
        ->and($second->totals()['by_freshness'])->toMatchArray(['current' => 1]);
});

it('exports byte-identical json and csv for an unchanged run', function (): void {
    $this->artisan('aleph:crawl', ['source' => 'waverly'])->assertSuccessful();

    $json = app(JsonInventory::class);
    $csv = app(CsvInventory::class);
    $inventory = inventory();

    expect($json->encode($inventory))->toBe($json->encode(inventory()))
        ->and($csv->encode($inventory))->toBe($csv->encode(inventory()));

    $lines = explode("\n", trim($csv->encode($inventory)));
    $urls = array_map(fn (string $line): string => explode(',', $line)[0], array_slice($lines, 1));
    $decoded = json_decode($json->encode($inventory), true, 512, JSON_THROW_ON_ERROR);

    expect($lines[0])->toBe(implode(',', InventoryResource::columns()))
        ->and($urls)->toBe(array_values(collect($urls)->sort()->all()))
        ->and(array_keys($decoded))->toBe(['bounds', 'totals', 'resources'])
        ->and(array_keys($decoded['resources'][0]))->toBe(InventoryResource::columns());
});

it('writes both inventories to disk from the command', function (): void {
    $this->artisan('aleph:crawl', ['source' => 'waverly'])->assertSuccessful();

    $json = tempnam(sys_get_temp_dir(), 'aleph').'.json';
    $csv = tempnam(sys_get_temp_dir(), 'aleph').'.csv';

    $this->artisan('aleph:inventory', ['source' => 'waverly', '--json' => $json, '--csv' => $csv])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($json), true, 512, JSON_THROW_ON_ERROR))
        ->toHaveKeys(['bounds', 'totals', 'resources'])
        ->and((string) file_get_contents($csv))->toStartWith('canonical_url,canonical_hash,');

    unlink($json);
    unlink($csv);
});

it('refuses to inventory an unknown run', function (): void {
    $this->artisan('aleph:inventory', ['source' => 'waverly', '--run' => 'missing'])->assertFailed();
    $this->artisan('aleph:inventory', ['source' => 'waverly'])->assertFailed();
});

it('refuses a run belonging to another source', function (): void {
    config()->set('aleph.web_sources.middle', webSource([
        'name' => 'Middle School',
        'seeds' => ['https://ms.ahsd.test/'],
        'allowed_hosts' => ['ms.ahsd.test'],
        'limits' => ['max_pages' => 20, 'max_depth' => 2],
    ]));
    bindSite(district()->page('https://ms.ahsd.test/', body: '<html><body>middle</body></html>'));

    $this->artisan('aleph:crawl', ['source' => 'middle'])->assertSuccessful();

    $run = app(IngestionRuns::class)->latest('web:middle', Capability::WebCrawl);

    $this->artisan('aleph:inventory', ['source' => 'waverly', '--run' => $run->id])->assertFailed();
});
