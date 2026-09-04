<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DiscoversSources;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Gutenberg\GutenbergAcquisitionFailure;
use Sifrious\Aleph\Connector\Gutenberg\GutenbergConnector;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Connector\Values\OperationRequest;

function gutenbergFixture(string $name): string
{
    $contents = file_get_contents(__DIR__."/../Fixtures/Gutenberg/{$name}");

    if (! is_string($contents)) {
        throw new RuntimeException("Missing Gutenberg fixture {$name}.");
    }

    return $contents;
}

function gutenbergCache(): string
{
    $directory = sys_get_temp_dir().'/aleph-gutenberg-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);

    return $directory;
}

function removeGutenbergCache(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        $path = $directory.'/'.$entry;
        is_dir($path) ? removeGutenbergCache($path) : unlink($path);
    }

    rmdir($directory);
}

it('implements only generic discovery and download acquisition contracts', function (): void {
    $cache = gutenbergCache();

    try {
        $connector = new GutenbergConnector(app(Factory::class), $cache);

        expect($connector)->toBeInstanceOf(Connector::class)
            ->toBeInstanceOf(DiscoversSources::class)
            ->toBeInstanceOf(DownloadsArtifacts::class)
            ->and($connector->version())->toContain('provisional-identity')
            ->and($connector->configuration()->required())->toBe(['cache_directory']);
    } finally {
        removeGutenbergCache($cache);
    }
});

it('discovers source metadata and labels provisional identity assumptions', function (): void {
    $cache = gutenbergCache();
    Http::fake([
        'www.gutenberg.org/ebooks/1342.rdf' => Http::response(
            gutenbergFixture('1342.rdf'),
            200,
            ['Content-Type' => 'application/rdf+xml'],
        ),
    ]);

    try {
        $connector = new GutenbergConnector(
            app(Factory::class),
            $cache,
            now: static fn (): string => '2026-09-04T10:00:00Z',
        );
        $sources = $connector->discoverSources(new OperationRequest('gutenberg:ebook/1342'));
        $source = $sources->sources[0];

        expect($sources->references())->toBe(['gutenberg:ebook/1342'])
            ->and($source->name)->toBe('Pride and Prejudice')
            ->and($source->metadata['creators'])->toBe(['Austen, Jane'])
            ->and($source->metadata['languages'])->toBe(['en'])
            ->and($source->metadata['artifact_candidates'])->toHaveCount(3)
            ->and($source->metadata['identity_assumption'])->toContain('MME-3813')
            ->and(is_file($cache.'/metadata/original/'.$source->metadata['metadata_sha256'].'.rdf'))->toBeTrue();
    } finally {
        removeGutenbergCache($cache);
    }
});

it('selects, preserves and identifies original bytes with non-destructive boundaries', function (): void {
    $cache = gutenbergCache();
    $text = gutenbergFixture('pg1342.txt');
    Http::fake([
        'www.gutenberg.org/ebooks/1342.rdf' => Http::response(gutenbergFixture('1342.rdf')),
        'www.gutenberg.org/cache/epub/1342/pg1342.txt' => Http::response($text, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'ETag' => '"fixture-etag"',
            'Last-Modified' => 'Fri, 04 Sep 2026 09:00:00 GMT',
        ]),
    ]);

    try {
        $connector = new GutenbergConnector(
            app(Factory::class),
            $cache,
            now: static fn (): string => '2026-09-04T10:00:00Z',
        );
        $artifact = $connector->downloadArtifact(
            new ArtifactRequest('gutenberg:ebook/1342', 'preferred'),
        );

        expect($artifact->reference)->toBe('https://www.gutenberg.org/cache/epub/1342/pg1342.txt')
            ->and($artifact->contents)->toBe($text)
            ->and($artifact->mediaType)->toBe('text/plain')
            ->and($artifact->metadata['sha256'])->toBe(hash('sha256', $text))
            ->and($artifact->metadata['content_identity'])->toBe('sha256:'.hash('sha256', $text))
            ->and($artifact->metadata['encoding'])->toBe([
                'detected' => 'UTF-8',
                'evidence' => 'metadata-content-type',
            ])
            ->and($artifact->metadata['boundaries']['header_end_byte'])->toBeInt()
            ->and($artifact->metadata['boundaries']['footer_start_byte'])
            ->toBeGreaterThan($artifact->metadata['boundaries']['header_end_byte'])
            ->and($artifact->metadata['original_bytes_preserved'])->toBeTrue()
            ->and($artifact->metadata['interpretation_performed'])->toBeFalse()
            ->and($artifact->metadata['http']['etag'])->toBe('"fixture-etag"')
            ->and($artifact->metadata['cache_hit'])->toBeFalse()
            ->and(file_get_contents($cache.'/blobs/'.hash('sha256', $text)))->toBe($text);
    } finally {
        removeGutenbergCache($cache);
    }
});

it('re-ingests idempotently from verified cache without changing acquisition time', function (): void {
    $cache = gutenbergCache();
    $calls = 0;
    $times = ['2026-09-04T10:00:00Z', '2026-09-04T10:00:01Z', '2026-09-04T10:00:02Z'];
    Http::fake(function () use (&$calls) {
        $calls++;

        return $calls === 1
            ? Http::response(gutenbergFixture('1342.rdf'))
            : Http::response(gutenbergFixture('pg1342.txt'), 200, ['Content-Type' => 'text/plain']);
    });

    try {
        $connector = new GutenbergConnector(
            app(Factory::class),
            $cache,
            now: static function () use (&$times): string {
                return array_shift($times) ?? 'unexpected';
            },
        );
        $request = new ArtifactRequest('gutenberg:ebook/1342', 'preferred');
        $first = $connector->downloadArtifact($request);
        $second = $connector->downloadArtifact($request);

        expect($calls)->toBe(2)
            ->and($first->metadata['cache_hit'])->toBeFalse()
            ->and($second->metadata['cache_hit'])->toBeTrue()
            ->and($second->metadata['acquired_at'])->toBe($first->metadata['acquired_at'])
            ->and($second->metadata['metadata_acquired_at'])->toBe($first->metadata['metadata_acquired_at'])
            ->and($second->metadata['content_identity'])->toBe($first->metadata['content_identity']);
    } finally {
        removeGutenbergCache($cache);
    }
});

it('retries bounded transient failures and does not retry permanent failures', function (): void {
    $cache = gutenbergCache();
    $sleeps = [];
    Http::fakeSequence()
        ->push('busy', 503)
        ->push(gutenbergFixture('1342.rdf'), 200);

    try {
        $connector = new GutenbergConnector(
            app(Factory::class),
            $cache,
            maxAttempts: 2,
            retryDelayMilliseconds: 25,
            sleep: static function (int $milliseconds) use (&$sleeps): void {
                $sleeps[] = $milliseconds;
            },
        );

        expect($connector->discoverSources(new OperationRequest('gutenberg:ebook/1342')))->toHaveCount(1)
            ->and($sleeps)->toBe([25]);

        Http::fakeSequence()->push('not found', 404);
        $connector->discoverSources(new OperationRequest('gutenberg:ebook/9999'));
    } catch (GutenbergAcquisitionFailure $failure) {
        expect($failure->getMessage())->toContain('HTTP 404');
        Http::assertSentCount(3);
    } finally {
        removeGutenbergCache($cache);
    }
});

it('rejects unlisted artifact URLs rather than constructing provider endpoints', function (): void {
    $cache = gutenbergCache();
    Http::fake([
        'www.gutenberg.org/ebooks/1342.rdf' => Http::response(gutenbergFixture('1342.rdf')),
    ]);

    try {
        $connector = new GutenbergConnector(app(Factory::class), $cache);

        expect(fn () => $connector->downloadArtifact(new ArtifactRequest(
            'gutenberg:ebook/1342',
            'https://www.gutenberg.org/files/1342/invented.txt',
        )))->toThrow(InvalidArgumentException::class, 'URL from Gutenberg metadata');
    } finally {
        removeGutenbergCache($cache);
    }
});
