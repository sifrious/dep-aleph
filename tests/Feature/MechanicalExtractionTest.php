<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Tests\Fixtures\FakeSite;
use Sifrious\Funes\Persistence\ObservationStore;

it('relates the Waverly calendar page to its external PDF artifact without crawling it', function (): void {
    $calendar = 'https://wav.ahsd.org/our_school/calendar';
    $pdf = 'https://drive.google.com/file/d/1gXu9XTbR2TTbYC-bHrUxJHboIZHS1TDw/preview';
    $embed = 'https://calendar.google.com/calendar/embed';
    $handbook = 'https://www.ahsd.org/calendar.pdf';
    $body = <<<HTML
    <html><body>
    <a href="{$handbook}">District calendar</a>
    <iframe src="{$pdf}" title="23-24 AHSD Calendar.pdf"></iframe>
    <embed src="{$embed}" type="text/html">
    </body></html>
    HTML;
    $site = (new FakeSite)->page($calendar, body: $body);

    config()->set('aleph.web_sources.waverly', webSource([
        'name' => 'Waverly Elementary',
        'seeds' => [$calendar],
        'allowed_hosts' => ['wav.ahsd.org'],
    ]));
    bindSite($site);

    $this->artisan('aleph:crawl', ['source' => 'waverly'])->assertSuccessful();

    $store = app(ObservationStore::class);
    $provenance = $store->discoveriesTo('web:waverly', $pdf);
    $relationships = DB::table('funes_discoveries')->orderBy('relationship')->pluck('relationship')->all();
    $candidate = DB::table('aleph_frontier_candidates')->where('canonical_url', $pdf)->first();
    $extraction = DB::table('funes_extractions')->where('extractor', 'aleph.html')->first();
    $result = json_decode((string) $extraction->result, true, 512, JSON_THROW_ON_ERROR);

    expect($provenance)->toHaveCount(1)
        ->and($provenance[0]->parentResourceReference)->toBe($calendar)
        ->and($provenance[0]->resourceReference)->toBe($pdf)
        ->and($provenance[0]->relationship)->toBe('iframe')
        ->and($relationships)->toBe(['embed', 'iframe', 'link'])
        ->and($candidate->state)->toBe('skipped')
        ->and($candidate->skip_reason)->toBe('external_host')
        ->and($candidate->origin)->toBe('iframe')
        ->and($site->requested)->toBe([$calendar])
        ->and($extraction->version)->toBe('1')
        ->and($result)->toMatchArray(['classification' => 'html', 'reference_count' => 3]);
});

it('preserves PDF bytes and records versioned embedded text extraction', function (): void {
    $url = 'https://wav.ahsd.org/media/waverly-calendar.pdf';
    $bytes = pdfWithEmbeddedText('Waverly School Calendar 2026-2027');
    $site = (new FakeSite)->page($url, contentType: 'application/pdf', body: $bytes);

    config()->set('aleph.web_sources.waverly', webSource([
        'name' => 'Waverly Elementary',
        'seeds' => [$url],
        'allowed_hosts' => ['wav.ahsd.org'],
    ]));
    bindSite($site);

    $this->artisan('aleph:crawl', ['source' => 'waverly'])->assertSuccessful();

    $observation = app(ObservationStore::class)->find('web:waverly', $url);
    $extraction = DB::table('funes_extractions')->where('observation_id', $observation?->id)->first();
    $extractionProvenance = DB::table('funes_extraction_provenance')->where('extraction_id', $extraction->id)->first();
    $runId = DB::table('aleph_ingestion_runs')->value('id');
    $result = json_decode((string) $extraction->result, true, 512, JSON_THROW_ON_ERROR);

    expect($observation)->not->toBeNull()
        ->and($observation?->payload)->toBe($bytes)
        ->and($observation?->payloadHash)->toBe(hash('sha256', $bytes))
        ->and($observation?->contentType)->toBe('application/pdf')
        ->and($extraction->extractor)->toBe('aleph.pdf')
        ->and($extraction->version)->toBe('1')
        ->and($extractionProvenance->producer_reference)->toBe('aleph:extractor/aleph.pdf')
        ->and($extractionProvenance->ingestion_run_reference)->toBe($runId)
        ->and($result['classification'])->toBe('pdf')
        ->and($result['text'])->toContain('Waverly School Calendar 2026-2027');
});

it('classifies unsupported observations without inventing content extraction', function (): void {
    $url = 'https://wav.ahsd.org/data/calendar.json';
    $site = (new FakeSite)->page($url, contentType: 'application/json', body: '{"year":2026}');

    config()->set('aleph.web_sources.waverly', webSource([
        'seeds' => [$url],
        'allowed_hosts' => ['wav.ahsd.org'],
    ]));
    bindSite($site);

    $this->artisan('aleph:crawl', ['source' => 'waverly'])->assertSuccessful();

    $extraction = DB::table('funes_extractions')->first();
    $result = json_decode((string) $extraction->result, true, 512, JSON_THROW_ON_ERROR);

    expect($extraction->extractor)->toBe('aleph.unsupported')
        ->and($extraction->version)->toBe('1')
        ->and($result)->toBe([
            'classification' => 'unsupported',
            'content_type' => 'application/json',
        ]);
});

it('extracts an empty document rather than failing on a body-less response', function (): void {
    $url = 'https://wav.ahsd.test/gone';
    $site = (new FakeSite)->page($url, status: 404, body: '');

    config()->set('aleph.web_sources.waverly', webSource([
        'seeds' => [$url],
        'allowed_hosts' => ['wav.ahsd.test'],
    ]));
    bindSite($site);

    $this->artisan('aleph:crawl', ['source' => 'waverly'])->assertSuccessful();

    $extraction = DB::table('funes_extractions')->first();
    $result = json_decode((string) $extraction->result, true, 512, JSON_THROW_ON_ERROR);

    expect($extraction->extractor)->toBe('aleph.html')
        ->and($extraction->status)->toBe('succeeded')
        ->and($extraction->failure)->toBeNull()
        ->and($result)->toBe([
            'classification' => 'html',
            'text' => '',
            'reference_count' => 0,
        ]);
});
