<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Podcast\LaunchPodcastIngestion;
use Sifrious\Aleph\Connector\Podcast\LaunchPodcastIngestionRequest;
use Sifrious\Aleph\Connector\Podcast\PodcastArtifactSubmission;
use Sifrious\Aleph\Connector\Podcast\PodcastConnector;
use Sifrious\Aleph\Connector\Podcast\PodcastEnclosureDownload;
use Sifrious\Aleph\Connector\Podcast\PodcastEnclosureDownloader;
use Sifrious\Aleph\Connector\Podcast\PodcastEpisodeResolution;
use Sifrious\Aleph\Connector\Podcast\PodcastEpisodeResolver;
use Sifrious\Aleph\Connector\Podcast\PodcastObservationWriter;
use Sifrious\Aleph\Connector\Podcast\UnfetchablePodcastEpisode;
use Sifrious\Aleph\Connector\Podcast\UnsupportedPodcastReference;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;

final class PodcastNullManualDispatcher implements ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void {}
}

final class FixturePodcastEpisodeResolver implements PodcastEpisodeResolver
{
    public function resolve(string $reference): PodcastEpisodeResolution
    {
        $normalized = trim($reference);

        return match ($normalized) {
            'https://podcasts.apple.com/us/podcast/some-show/id1234567890' => new PodcastEpisodeResolution(
                'apple:episode:1789456001',
                'https://fixtures.example.test/enclosures/apple-1789456001.m4a',
                ['provider' => 'apple_podcasts', 'input' => 'show_url'],
            ),
            'https://podcasts.apple.com/us/podcast/some-show/id1234567890?i=1789456001' => new PodcastEpisodeResolution(
                'apple:episode:1789456001',
                'https://fixtures.example.test/enclosures/apple-1789456001.m4a',
                ['provider' => 'apple_podcasts', 'input' => 'episode_url'],
            ),
            '1789456001' => new PodcastEpisodeResolution(
                'apple:episode:1789456001',
                'https://fixtures.example.test/enclosures/apple-1789456001.m4a',
                ['provider' => 'apple_music', 'input' => 'episode_id'],
            ),
            'https://rss.example.test/podcasts/episode-42' => new PodcastEpisodeResolution(
                'rss:episode:42',
                'https://rss.example.test/audio/episode-42.mp3',
                ['provider' => 'rss', 'input' => 'episode_url'],
            ),
            'https://music.apple.com/us/song/locked-track/999999999' => throw new UnfetchablePodcastEpisode(
                'The referenced Apple Music track is DRM-protected and cannot be fetched as a podcast enclosure.',
            ),
            default => throw new UnsupportedPodcastReference('The podcast reference is not supported by the fixture resolver.'),
        };
    }
}

final class FixturePodcastEnclosureDownloader implements PodcastEnclosureDownloader
{
    /** @var list<string> */
    public array $urls = [];

    public function download(string $enclosureUrl): PodcastEnclosureDownload
    {
        $this->urls[] = $enclosureUrl;

        $fixtures = [
            'https://fixtures.example.test/enclosures/apple-1789456001.m4a' => ['audio/mp4', 'apple-fixture-enclosure'],
            'https://rss.example.test/audio/episode-42.mp3' => ['audio/mpeg', 'rss-fixture-enclosure'],
        ];
        [$mediaType, $contents] = $fixtures[$enclosureUrl] ?? throw new RuntimeException('Unknown podcast enclosure fixture URL.');

        return new PodcastEnclosureDownload(
            $mediaType,
            $contents,
            ['downloaded_by' => 'fixture'],
        );
    }
}

final class RecordingPodcastWriter implements PodcastObservationWriter
{
    /** @var list<PodcastArtifactSubmission> */
    public array $submissions = [];

    public function write(PodcastArtifactSubmission $submission, string $attemptId): string
    {
        $this->submissions[] = $submission;

        return 'accepted:'.$submission->episodeIdentity;
    }
}

function podcastLauncher(): array
{
    $registry = app(ConnectorRegistry::class);
    $downloader = new FixturePodcastEnclosureDownloader;
    $connector = new PodcastConnector($downloader);
    $registry->register($connector);
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'Podcast account',
        externalAccountId: 'podcast:account:1',
    );
    $launch = new LaunchIngestion(
        $registry,
        app(ConnectorInstallations::class),
        app(IngestionRuns::class),
        new PodcastNullManualDispatcher,
    );
    $writer = new RecordingPodcastWriter;
    $resolver = new FixturePodcastEpisodeResolver;

    return [
        new LaunchPodcastIngestion($launch, app(IngestionRuns::class), $registry, $resolver, $writer),
        $installation,
        $downloader,
        $writer,
    ];
}

it('ingests one podcast show or episode reference into one enclosure artifact', function (): void {
    [$launcher, $installation, $downloader, $writer] = podcastLauncher();
    $request = new LaunchPodcastIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'podcast:workspace/main',
        reference: 'https://podcasts.apple.com/us/podcast/some-show/id1234567890',
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:podcast/1'),
    );

    $result = $launcher->launch($request);
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;

    expect($result->replayed)->toBeFalse()
        ->and($result->episodeIdentity)->toBe('apple:episode:1789456001')
        ->and($result->enclosureUrl)->toBe('https://fixtures.example.test/enclosures/apple-1789456001.m4a')
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->acceptedReferences)->toBe($result->acceptedReferences)
        ->and($downloader->urls)->toBe(['https://fixtures.example.test/enclosures/apple-1789456001.m4a'])
        ->and($submitted)->not->toBeNull()
        ->and($submitted?->episodeIdentity)->toBe('apple:episode:1789456001')
        ->and($submitted?->mediaType)->toBe('audio/mp4')
        ->and($submitted?->checksum)->toBe(hash('sha256', 'apple-fixture-enclosure'));
});

it('replays by canonical episode identity even when input reference format changes', function (): void {
    [$launcher, $installation, $downloader, $writer] = podcastLauncher();
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:podcast/2');

    $first = $launcher->launch(new LaunchPodcastIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'podcast:workspace/main',
        reference: 'https://podcasts.apple.com/us/podcast/some-show/id1234567890?i=1789456001',
        authorization: $authorization,
    ));
    $duplicate = $launcher->launch(new LaunchPodcastIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'podcast:workspace/main',
        reference: '1789456001',
        authorization: $authorization,
    ));

    expect($duplicate->replayed)->toBeTrue()
        ->and($duplicate->runId)->toBe($first->runId)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and($downloader->urls)->toBe(['https://fixtures.example.test/enclosures/apple-1789456001.m4a'])
        ->and($writer->submissions)->toHaveCount(1);
});

it('supports rss episode references and rejects drm-only apple music tracks', function (): void {
    [$launcher, $installation] = podcastLauncher();

    $rss = $launcher->launch(new LaunchPodcastIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'podcast:workspace/main',
        reference: 'https://rss.example.test/podcasts/episode-42',
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:podcast/3'),
    ));

    expect($rss->episodeIdentity)->toBe('rss:episode:42')
        ->and($rss->enclosureUrl)->toBe('https://rss.example.test/audio/episode-42.mp3')
        ->and(fn () => $launcher->launch(new LaunchPodcastIngestionRequest(
            sourceInstallationId: $installation->id,
            sourceReference: 'podcast:workspace/main',
            reference: 'https://music.apple.com/us/song/locked-track/999999999',
            authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:podcast/4'),
        )))->toThrow(UnfetchablePodcastEpisode::class, 'DRM-protected');
});

it('ships a direct enclosure resolver without treating ordinary pages as media', function (): void {
    $resolver = app(PodcastEpisodeResolver::class);
    $url = 'https://cdn.example.test/podcast/episode-42.mp3';

    expect($resolver->resolve('enclosure:'.$url)->enclosureUrl)->toBe($url)
        ->and(fn () => $resolver->resolve('https://podcasts.example.test/show/42'))
        ->toThrow(UnsupportedPodcastReference::class);
});
