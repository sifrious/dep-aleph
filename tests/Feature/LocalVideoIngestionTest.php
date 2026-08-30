<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\VideoFile\LaunchLocalVideoIngestion;
use Sifrious\Aleph\Connector\VideoFile\LaunchLocalVideoIngestionRequest;
use Sifrious\Aleph\Connector\VideoFile\LocalVideoFilePayload;
use Sifrious\Aleph\Connector\VideoFile\VideoFileArtifactSubmission;
use Sifrious\Aleph\Connector\VideoFile\VideoFileConnector;
use Sifrious\Aleph\Connector\VideoFile\VideoFileObservationWriter;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;

final class LocalVideoNullManualDispatcher implements ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void {}
}

final class RecordingVideoFileWriter implements VideoFileObservationWriter
{
    /** @var list<VideoFileArtifactSubmission> */
    public array $submissions = [];

    public function write(VideoFileArtifactSubmission $submission, string $attemptId): string
    {
        $this->submissions[] = $submission;

        return 'accepted:'.$submission->artifactReference;
    }
}

function localVideoLauncher(): array
{
    $registry = app(ConnectorRegistry::class);
    $connector = new VideoFileConnector;
    $registry->register($connector);
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'Local video files',
        owner: 'identity:user/mary',
    );
    $launch = new LaunchIngestion(
        $registry,
        app(ConnectorInstallations::class),
        app(IngestionRuns::class),
        new LocalVideoNullManualDispatcher,
    );
    $writer = new RecordingVideoFileWriter;

    return [
        new LaunchLocalVideoIngestion($launch, app(IngestionRuns::class), $registry, $writer),
        $installation,
        $writer,
    ];
}

function tempVideo(string $contents, string $extension = 'mp4'): string
{
    $path = sys_get_temp_dir().'/aleph-video-'.bin2hex(random_bytes(6)).'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

it('ingests a local path through launch ingestion and stores one artifact', function (): void {
    [$launcher, $installation, $writer] = localVideoLauncher();
    $path = tempVideo('fixture-video-bytes');
    $request = LaunchLocalVideoIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:capture:main',
        path: $path,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:video-file/1'),
    );

    $result = $launcher->launch($request);
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;

    expect($result->replayed)->toBeFalse()
        ->and($run)->not->toBeNull()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->acceptedReferences)->toBe($result->acceptedReferences)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted)->not->toBeNull()
        ->and($submitted?->artifactReference)->toBe('file://'.realpath($path))
        ->and($submitted?->checksum)->toBe(hash('sha256', 'fixture-video-bytes'))
        ->and($submitted?->mediaType)->toBe('video/mp4');

    @unlink($path);
});

it('ingests direct file payload bytes without requiring a local path', function (): void {
    [$launcher, $installation, $writer] = localVideoLauncher();
    $payload = new LocalVideoFilePayload('capture.mov', 'fixture-capture-bytes', 'video/quicktime');
    $request = LaunchLocalVideoIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:capture:main',
        file: $payload,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:video-file/2'),
    );

    $result = $launcher->launch($request);
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;

    expect($result->replayed)->toBeFalse()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted?->artifactReference)->toBe('memory://capture.mov#sha256:'.hash('sha256', 'fixture-capture-bytes'))
        ->and($submitted?->checksum)->toBe(hash('sha256', 'fixture-capture-bytes'))
        ->and($submitted?->mediaType)->toBe('video/quicktime');
});

it('returns the original run and avoids duplicate artifact writes for the same file', function (): void {
    [$launcher, $installation, $writer] = localVideoLauncher();
    $path = tempVideo('same-video-bytes');
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:video-file/3');
    $request = LaunchLocalVideoIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:capture:main',
        path: $path,
        authorization: $authorization,
    );

    $first = $launcher->launch($request);
    $duplicate = $launcher->launch($request);

    expect($duplicate->replayed)->toBeTrue()
        ->and($duplicate->runId)->toBe($first->runId)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and($writer->submissions)->toHaveCount(1);

    @unlink($path);
});
