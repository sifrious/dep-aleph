<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\SpokenSound\LaunchSpokenSoundIngestion;
use Sifrious\Aleph\Connector\SpokenSound\LaunchSpokenSoundIngestionRequest;
use Sifrious\Aleph\Connector\SpokenSound\LocalSpokenSoundFilePayload;
use Sifrious\Aleph\Connector\SpokenSound\SpokenSoundArtifactSubmission;
use Sifrious\Aleph\Connector\SpokenSound\SpokenSoundConnector;
use Sifrious\Aleph\Connector\SpokenSound\SpokenSoundObservationWriter;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;

final class SpokenSoundNullManualDispatcher implements ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void {}
}

final class RecordingSpokenSoundWriter implements SpokenSoundObservationWriter
{
    /** @var list<SpokenSoundArtifactSubmission> */
    public array $submissions = [];

    public function write(SpokenSoundArtifactSubmission $submission, string $attemptId): string
    {
        $this->submissions[] = $submission;

        return 'accepted:'.$submission->artifactReference;
    }
}

function spokenSoundLauncher(): array
{
    $registry = app(ConnectorRegistry::class);
    $connector = new SpokenSoundConnector;
    $registry->register($connector);
    $installations = app(ConnectorInstallations::class);
    $firstInstallation = $installations->create(
        $connector,
        'Spoken sound files',
        owner: 'identity:user/mary',
    );
    $secondInstallation = $installations->create(
        $connector,
        'Spoken sound files backup',
        owner: 'identity:user/mary',
    );
    $launch = new LaunchIngestion(
        $registry,
        $installations,
        app(IngestionRuns::class),
        new SpokenSoundNullManualDispatcher,
    );
    $writer = new RecordingSpokenSoundWriter;

    return [
        new LaunchSpokenSoundIngestion($launch, app(IngestionRuns::class), $registry, $writer),
        $firstInstallation,
        $secondInstallation,
        $writer,
    ];
}

function tempSpokenSound(string $contents, string $extension = 'm4a'): string
{
    $path = sys_get_temp_dir().'/aleph-spoken-sound-'.bin2hex(random_bytes(6)).'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

it('ingests one local spoken sound path and stores audio metadata', function (): void {
    [$launcher, $installation, , $writer] = spokenSoundLauncher();
    $path = tempSpokenSound('fixture-spoken-sound-bytes');
    $request = LaunchSpokenSoundIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:voice-notes',
        path: $path,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:spoken-sound/1'),
        durationSeconds: 11.25,
        containerMetadata: ['codec' => 'aac', 'channels' => 1],
    );

    $result = $launcher->launch($request);
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;

    expect($result->replayed)->toBeFalse()
        ->and($run)->not->toBeNull()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->acceptedReferences)->toBe($result->acceptedReferences)
        ->and($run?->parameters['sha256'] ?? null)->toBe(hash('sha256', 'fixture-spoken-sound-bytes'))
        ->and($run?->parameters['duration_seconds'] ?? null)->toBe(11.25)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted?->artifactReference)->toBe('file://'.realpath($path))
        ->and($submitted?->mediaType)->toBe('audio/mp4')
        ->and($submitted?->bytes)->toBe(strlen('fixture-spoken-sound-bytes'))
        ->and($submitted?->checksum)->toBe(hash('sha256', 'fixture-spoken-sound-bytes'))
        ->and($submitted?->durationSeconds)->toBe(11.25)
        ->and($submitted?->containerMetadata)->toMatchArray([
            'codec' => 'aac',
            'channels' => 1,
            'extension' => 'm4a',
            'filename' => basename($path),
            'mime_type' => 'audio/mp4',
        ]);

    @unlink($path);
});

it('ingests direct spoken sound file payload bytes', function (): void {
    [$launcher, $installation, , $writer] = spokenSoundLauncher();
    $payload = new LocalSpokenSoundFilePayload('voice-note.mp3', 'fixture-voice-bytes', 'audio/mpeg');
    $request = LaunchSpokenSoundIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:voice-notes',
        file: $payload,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:spoken-sound/2'),
        durationSeconds: 5.5,
        containerMetadata: ['codec' => 'mp3'],
    );

    $result = $launcher->launch($request);
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;
    $expectedReference = 'memory://voice-note.mp3#sha256:'.hash('sha256', 'fixture-voice-bytes');

    expect($result->replayed)->toBeFalse()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted?->artifactReference)->toBe($expectedReference)
        ->and($submitted?->checksum)->toBe(hash('sha256', 'fixture-voice-bytes'))
        ->and($submitted?->durationSeconds)->toBe(5.5)
        ->and($submitted?->containerMetadata['codec'] ?? null)->toBe('mp3');
});

it('replays the same spoken sound bytes on duplicate submit', function (): void {
    [$launcher, $installation, , $writer] = spokenSoundLauncher();
    $firstPath = tempSpokenSound('same-audio-bytes', 'wav');
    $duplicatePath = tempSpokenSound('same-audio-bytes', 'wav');
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:spoken-sound/3');

    $first = $launcher->launch(LaunchSpokenSoundIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:voice-notes',
        path: $firstPath,
        authorization: $authorization,
        durationSeconds: 3.0,
    ));
    $duplicate = $launcher->launch(LaunchSpokenSoundIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:voice-notes',
        path: $duplicatePath,
        authorization: $authorization,
        durationSeconds: 3.0,
    ));

    expect($duplicate->replayed)->toBeTrue()
        ->and($duplicate->runId)->toBe($first->runId)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and($writer->submissions)->toHaveCount(1);

    @unlink($firstPath);
    @unlink($duplicatePath);
});

it('uses installation plus content hash for spoken sound idempotency', function (): void {
    [$launcher, $installationA, $installationB, $writer] = spokenSoundLauncher();
    $payload = new LocalSpokenSoundFilePayload('note.m4a', 'same-bytes-across-installations', 'audio/mp4');
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:spoken-sound/4');

    $first = $launcher->launch(LaunchSpokenSoundIngestionRequest::fromFile(
        sourceInstallationId: $installationA->id,
        sourceReference: 'desktop:voice-notes:a',
        file: $payload,
        authorization: $authorization,
    ));
    $second = $launcher->launch(LaunchSpokenSoundIngestionRequest::fromFile(
        sourceInstallationId: $installationB->id,
        sourceReference: 'desktop:voice-notes:b',
        file: $payload,
        authorization: $authorization,
    ));

    expect($first->runId)->not->toBe($second->runId)
        ->and($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeFalse()
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(2)
        ->and($writer->submissions)->toHaveCount(2);
});
