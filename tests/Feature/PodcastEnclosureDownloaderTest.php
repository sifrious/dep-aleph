<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sifrious\Aleph\Connector\Podcast\HttpPodcastEnclosureDownloader;
use Sifrious\Aleph\Connector\Podcast\RetryablePodcastEnclosureDownloadFailure;
use Sifrious\Aleph\Connector\Podcast\UnfetchablePodcastEpisode;

it('downloads a podcast enclosure over http with stable media metadata', function (): void {
    Http::fake([
        'https://media.example.test/episode-1.mp3' => Http::response('fixture-audio', 200, ['Content-Type' => 'audio/mpeg; charset=binary']),
    ]);
    $downloader = new HttpPodcastEnclosureDownloader(app(\Illuminate\Http\Client\Factory::class));

    $download = $downloader->download('https://media.example.test/episode-1.mp3');

    expect($download->mediaType)->toBe('audio/mpeg')
        ->and($download->contents)->toBe('fixture-audio')
        ->and($download->metadata['http_status'])->toBe(200)
        ->and($download->metadata['content_length'])->toBe(strlen('fixture-audio'));
});

it('marks transient enclosure failures as retryable', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('cURL error 28: timed out'));
    $downloader = new HttpPodcastEnclosureDownloader(app(\Illuminate\Http\Client\Factory::class));

    expect(fn () => $downloader->download('https://media.example.test/episode-2.m4a'))
        ->toThrow(RetryablePodcastEnclosureDownloadFailure::class, 'timed out');
});

it('rejects non-http or non-successful enclosure downloads as unfetchable', function (): void {
    Http::fake([
        'https://media.example.test/not-found.mp3' => Http::response('missing', 404),
        'https://media.example.test/rate-limited.mp3' => Http::response('busy', 429),
    ]);
    $downloader = new HttpPodcastEnclosureDownloader(app(\Illuminate\Http\Client\Factory::class));

    expect(fn () => $downloader->download('file:///tmp/episode.mp3'))
        ->toThrow(UnfetchablePodcastEpisode::class, 'http or https')
        ->and(fn () => $downloader->download('https://media.example.test/not-found.mp3'))
        ->toThrow(UnfetchablePodcastEpisode::class, 'HTTP 404')
        ->and(fn () => $downloader->download('https://media.example.test/rate-limited.mp3'))
        ->toThrow(RetryablePodcastEnclosureDownloadFailure::class, 'HTTP 429');
});

it('sends a package user-agent when fetching enclosure bytes', function (): void {
    $captured = [];
    Http::fake(function (Request $request) use (&$captured) {
        $captured[] = $request->header('User-Agent')[0] ?? null;

        return Http::response('audio', 200, ['Content-Type' => 'audio/mp4']);
    });
    $downloader = new HttpPodcastEnclosureDownloader(app(\Illuminate\Http\Client\Factory::class));
    $downloader->download('https://media.example.test/episode-3.m4a');

    expect($captured)->toBe(['AlephPodcastIngestion/1.0']);
});
