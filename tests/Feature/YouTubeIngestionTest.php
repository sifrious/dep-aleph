<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\YouTube\LaunchYouTubeIngestion;
use Sifrious\Aleph\Connector\YouTube\LaunchYouTubeIngestionRequest;
use Sifrious\Aleph\Connector\YouTube\RetryableYouTubeDownloadFailure;
use Sifrious\Aleph\Connector\YouTube\YouTubeArtifactSubmission;
use Sifrious\Aleph\Connector\YouTube\YouTubeCanonicalUrl;
use Sifrious\Aleph\Connector\YouTube\YouTubeConnector;
use Sifrious\Aleph\Connector\YouTube\YouTubeDownload;
use Sifrious\Aleph\Connector\YouTube\YouTubeDownloader;
use Sifrious\Aleph\Connector\YouTube\YouTubeObservationWriter;
use Sifrious\Aleph\Connector\YouTube\YouTubeTranscript;
use Sifrious\Aleph\Ingestion\Capability as IngestionCapability;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;

final class NullManualDispatcher implements ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void {}
}

final class RecordingYouTubeDownloader implements YouTubeDownloader
{
    /** @var list<string> */
    public array $urls = [];

    public bool $fail = false;

    public function __construct(
        private readonly string $contents = 'binary-youtube-video',
        private readonly ?YouTubeTranscript $transcript = null,
    ) {}

    public function download(YouTubeCanonicalUrl $url): YouTubeDownload
    {
        $this->urls[] = $url->value;

        if ($this->fail) {
            throw new RetryableYouTubeDownloadFailure('transient_youtube_download_failure');
        }

        return new YouTubeDownload(
            mediaType: 'video/mp4',
            contents: $this->contents,
            metadata: [
                'id' => 'dQw4w9WgXcQ',
                'title' => 'Never Gonna Give You Up',
                'duration' => 213,
            ],
            transcript: $this->transcript,
        );
    }
}

final class RecordingYouTubeWriter implements YouTubeObservationWriter
{
    /** @var list<YouTubeArtifactSubmission> */
    public array $submissions = [];

    public function write(YouTubeArtifactSubmission $submission, string $attemptId): string
    {
        $this->submissions[] = $submission;

        return 'accepted:'.$submission->canonicalUrl;
    }
}

function youtubeLauncher(RecordingYouTubeDownloader $downloader): array
{
    $registry = app(ConnectorRegistry::class);
    $connector = new YouTubeConnector($downloader);
    $registry->register($connector);
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'YouTube account',
        externalAccountId: 'youtube:channel:1',
    );
    $launch = new LaunchIngestion(
        $registry,
        app(ConnectorInstallations::class),
        app(IngestionRuns::class),
        new NullManualDispatcher,
    );
    $writer = new RecordingYouTubeWriter;

    return [
        new LaunchYouTubeIngestion($launch, app(IngestionRuns::class), $registry, $writer),
        $installation,
        $downloader,
        $writer,
    ];
}

it('launches ingestion and stores one YouTube artifact envelope with run provenance', function (): void {
    [$launcher, $installation, $downloader, $writer] = youtubeLauncher(new RecordingYouTubeDownloader(
        transcript: new YouTubeTranscript('text/vtt', "WEBVTT\n\n00:00.000 --> 00:02.000\nHello", 'en'),
    ));
    $request = new LaunchYouTubeIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'youtube:workspace/main',
        url: 'https://youtu.be/dQw4w9WgXcQ?t=23',
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:youtube/1'),
    );

    $result = $launcher->launch($request);
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;

    expect($result->replayed)->toBeFalse()
        ->and($run)->not->toBeNull()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->acceptedReferences)->toBe($result->acceptedReferences)
        ->and($downloader->urls)->toBe(['https://www.youtube.com/watch?v=dQw4w9WgXcQ'])
        ->and($submitted)->not->toBeNull()
        ->and($submitted?->runId)->toBe($run?->id)
        ->and($submitted?->canonicalUrl)->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->and($submitted?->checksum)->toBe(hash('sha256', 'binary-youtube-video'))
        ->and($submitted?->transcript['language'])->toBe('en')
        ->and($submitted?->videoMetadata['id'])->toBe('dQw4w9WgXcQ');
});

it('replays the same canonical url without creating a second run or artifact', function (): void {
    [$launcher, $installation, $downloader, $writer] = youtubeLauncher(new RecordingYouTubeDownloader(
        contents: 'video-without-transcript',
    ));
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:youtube/2');

    $first = $launcher->launch(new LaunchYouTubeIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'youtube:workspace/main',
        url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&feature=youtu.be',
        authorization: $authorization,
    ));
    $duplicate = $launcher->launch(new LaunchYouTubeIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'youtube:workspace/main',
        url: 'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
        authorization: $authorization,
    ));
    $submitted = $writer->submissions[0] ?? null;

    expect($duplicate->replayed)->toBeTrue()
        ->and($duplicate->runId)->toBe($first->runId)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and($downloader->urls)->toBe(['https://www.youtube.com/watch?v=dQw4w9WgXcQ'])
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted?->transcript)->toBeNull();
});

it('records retryable failure when youtube download cannot be completed', function (): void {
    [$launcher, $installation, $downloader] = youtubeLauncher(new RecordingYouTubeDownloader);
    $downloader->fail = true;

    expect(fn () => $launcher->launch(new LaunchYouTubeIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'youtube:workspace/main',
        url: 'https://youtube.com/shorts/dQw4w9WgXcQ',
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:youtube/3'),
    )))->toThrow(RetryableYouTubeDownloadFailure::class, 'transient_youtube_download_failure');

    $run = app(IngestionRuns::class)->latest('youtube:workspace/main', IngestionCapability::DownloadArtifact);

    expect($run)->not->toBeNull()
        ->and($run?->status)->toBe(RunStatus::Failed)
        ->and($run?->failure?->retryable)->toBeTrue()
        ->and(DB::table('aleph_ingestion_attempts')->count())->toBe(1);
});
