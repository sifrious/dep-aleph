<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\ScoreTab\AbsentScoreTabLocalModel;
use Sifrious\Aleph\Connector\ScoreTab\LaunchScoreTabIngestion;
use Sifrious\Aleph\Connector\ScoreTab\LaunchScoreTabIngestionRequest;
use Sifrious\Aleph\Connector\ScoreTab\LocalScoreTabFilePayload;
use Sifrious\Aleph\Connector\ScoreTab\ScoreTabArtifactSubmission;
use Sifrious\Aleph\Connector\ScoreTab\ScoreTabConnector;
use Sifrious\Aleph\Connector\ScoreTab\ScoreTabDerivationRecorder;
use Sifrious\Aleph\Connector\ScoreTab\ScoreTabLocalModel;
use Sifrious\Aleph\Connector\ScoreTab\ScoreTabModelDerivation;
use Sifrious\Aleph\Connector\ScoreTab\ScoreTabObservationWriter;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;

final class ScoreTabNullManualDispatcher implements ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void {}
}

final class RecordingScoreTabWriter implements ScoreTabObservationWriter
{
    /** @var list<ScoreTabArtifactSubmission> */
    public array $submissions = [];

    public function write(ScoreTabArtifactSubmission $submission, string $attemptId): string
    {
        $this->submissions[] = $submission;

        return 'accepted:'.$submission->artifactReference;
    }
}

final class RecordingScoreTabDerivationRecorder implements ScoreTabDerivationRecorder
{
    /** @var list<array{observation_id: string, derivation: ScoreTabModelDerivation, run_id: string}> */
    public array $records = [];

    public function record(string $observationId, ScoreTabModelDerivation $derivation, string $runId): void
    {
        $this->records[] = [
            'observation_id' => $observationId,
            'derivation' => $derivation,
            'run_id' => $runId,
        ];
    }
}

final class FixtureScoreTabLocalModel implements ScoreTabLocalModel
{
    public function __construct(
        private readonly bool $available = true,
        private readonly ?ScoreTabModelDerivation $derivation = null,
        private readonly bool $throw = false,
    ) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function derive(string $contents, string $mediaType, string $name): ?ScoreTabModelDerivation
    {
        if ($this->throw) {
            throw new RuntimeException('local score/tab model failed');
        }

        return $this->derivation ?? new ScoreTabModelDerivation(
            'fixture-oss-score-model',
            '0.1.0',
            [
                'source_bytes_sha256' => hash('sha256', $contents),
                'media_type' => $mediaType,
                'name' => $name,
                'notes' => [['pitch' => 'C4', 'duration' => 'quarter']],
            ],
        );
    }
}

/**
 * @return array{0: LaunchScoreTabIngestion, 1: mixed, 2: mixed, 3: RecordingScoreTabWriter, 4: RecordingScoreTabDerivationRecorder}
 */
function scoreTabLauncher(
    ?ScoreTabLocalModel $model = null,
    ?ScoreTabObservationWriter $writer = null,
    ?ScoreTabDerivationRecorder $derivations = null,
): array {
    $registry = app(ConnectorRegistry::class);
    $connector = new ScoreTabConnector;
    $registry->register($connector);
    $installations = app(ConnectorInstallations::class);
    $firstInstallation = $installations->create(
        $connector,
        'Score and tab files',
        owner: 'identity:user/mary',
    );
    $secondInstallation = $installations->create(
        $connector,
        'Score and tab files backup',
        owner: 'identity:user/mary',
    );
    $launch = new LaunchIngestion(
        $registry,
        $installations,
        app(IngestionRuns::class),
        new ScoreTabNullManualDispatcher,
    );
    $writer ??= new RecordingScoreTabWriter;
    $derivations ??= new RecordingScoreTabDerivationRecorder;
    $model ??= new AbsentScoreTabLocalModel;

    return [
        new LaunchScoreTabIngestion(
            $launch,
            app(IngestionRuns::class),
            $registry,
            $writer,
            $model,
            $derivations,
        ),
        $firstInstallation,
        $secondInstallation,
        $writer,
        $derivations,
    ];
}

function tempScoreTab(string $contents, string $extension = 'musicxml'): string
{
    $path = sys_get_temp_dir().'/aleph-score-tab-'.bin2hex(random_bytes(6)).'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

it('ingests a local MusicXML path through launch ingestion and stores one artifact', function (): void {
    [$launcher, $installation, , $writer, $derivations] = scoreTabLauncher();
    $path = tempScoreTab('<score-partwise version="3.1"><part-list/></score-partwise>');
    $request = LaunchScoreTabIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:scores',
        path: $path,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:score-tab/1'),
    );

    $result = $launcher->launch($request);
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;

    expect($result->replayed)->toBeFalse()
        ->and($run)->not->toBeNull()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->acceptedReferences)->toBe($result->acceptedReferences)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted?->artifactReference)->toBe('file://'.realpath($path))
        ->and($submitted?->checksum)->toBe(hash('sha256', '<score-partwise version="3.1"><part-list/></score-partwise>'))
        ->and($submitted?->mediaType)->toBe('application/vnd.recordare.musicxml+xml')
        ->and($submitted?->metadata['format'] ?? null)->toBe('musicxml')
        ->and($derivations->records)->toBe([]);

    @unlink($path);
});

it('ingests guitar tab and score image file payloads without a local model', function (): void {
    [$launcher, $installation, , $writer] = scoreTabLauncher();
    $tab = new LocalScoreTabFilePayload('riff.tab', "e|---0---\nB|---1---\n", 'text/plain');
    $image = new LocalScoreTabFilePayload('score.png', 'fake-png-bytes', 'image/png');

    $tabResult = $launcher->launch(LaunchScoreTabIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:scores',
        file: $tab,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:score-tab/2a'),
    ));
    $imageResult = $launcher->launch(LaunchScoreTabIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:scores',
        file: $image,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:score-tab/2b'),
    ));

    expect($tabResult->replayed)->toBeFalse()
        ->and($imageResult->replayed)->toBeFalse()
        ->and($writer->submissions)->toHaveCount(2)
        ->and($writer->submissions[0]->mediaType)->toBe('text/plain')
        ->and($writer->submissions[0]->metadata['format'] ?? null)->toBe('ascii_tab')
        ->and($writer->submissions[1]->mediaType)->toBe('image/png')
        ->and($writer->submissions[1]->metadata['format'] ?? null)->toBe('score_image');
});

it('returns the original run and avoids duplicate artifact writes for the same file', function (): void {
    [$launcher, $installation, , $writer] = scoreTabLauncher();
    $path = tempScoreTab('same-score-bytes', 'gp5');
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:score-tab/3');
    $request = LaunchScoreTabIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:scores',
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

it('scopes score/tab idempotency to the source installation', function (): void {
    [$launcher, $installationA, $installationB, $writer] = scoreTabLauncher();
    $payload = new LocalScoreTabFilePayload('shared.musicxml', 'identical-score-bytes');
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:score-tab/4');

    $first = $launcher->launch(LaunchScoreTabIngestionRequest::fromFile(
        sourceInstallationId: $installationA->id,
        sourceReference: 'desktop:scores:a',
        file: $payload,
        authorization: $authorization,
    ));
    $second = $launcher->launch(LaunchScoreTabIngestionRequest::fromFile(
        sourceInstallationId: $installationB->id,
        sourceReference: 'desktop:scores:b',
        file: $payload,
        authorization: $authorization,
    ));

    expect($first->runId)->not->toBe($second->runId)
        ->and($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeFalse()
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(2)
        ->and($writer->submissions)->toHaveCount(2);
});

it('succeeds when the optional free local model is absent', function (): void {
    [$launcher, $installation, , $writer, $derivations] = scoreTabLauncher(new AbsentScoreTabLocalModel);
    $payload = new LocalScoreTabFilePayload('solo.pdf', '%PDF-score-fixture', 'application/pdf');

    $result = $launcher->launch(LaunchScoreTabIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:scores',
        file: $payload,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:score-tab/5'),
    ));
    $run = app(IngestionRuns::class)->find($result->runId);

    expect($result->replayed)->toBeFalse()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($derivations->records)->toBe([]);
});

it('records an optional local model derivation without failing ingest when the model throws', function (): void {
    $derivations = new RecordingScoreTabDerivationRecorder;
    [$launcher, $installation, , $writer] = scoreTabLauncher(
        new FixtureScoreTabLocalModel(throw: true),
        derivations: $derivations,
    );
    $payload = new LocalScoreTabFilePayload('broken-model.musicxml', '<score/>');

    $result = $launcher->launch(LaunchScoreTabIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:scores',
        file: $payload,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:score-tab/6'),
    ));
    $run = app(IngestionRuns::class)->find($result->runId);

    expect($run?->status)->toBe(RunStatus::Completed)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($derivations->records)->toBe([]);
});

it('stores a present free local model derivation as a new version without overwriting raw bytes', function (): void {
    $raw = '<score-partwise version="3.1"><part id="P1"/></score-partwise>';
    $derivations = new RecordingScoreTabDerivationRecorder;
    $model = new FixtureScoreTabLocalModel(
        derivation: new ScoreTabModelDerivation('oss-omr', '1.2.0', [
            'kind' => 'music_document_graph_stub',
            'parts' => [['id' => 'P1']],
        ]),
    );
    [$launcher, $installation, , $writer] = scoreTabLauncher($model, derivations: $derivations);

    $result = $launcher->launch(LaunchScoreTabIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:scores',
        file: new LocalScoreTabFilePayload('melody.musicxml', $raw, 'application/vnd.recordare.musicxml+xml'),
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:score-tab/7'),
    ));
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;
    $recorded = $derivations->records[0] ?? null;

    expect($result->replayed)->toBeFalse()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted?->contents)->toBe($raw)
        ->and($submitted?->checksum)->toBe(hash('sha256', $raw))
        ->and($derivations->records)->toHaveCount(1)
        ->and($recorded['observation_id'] ?? null)->toBe($result->acceptedReferences[0] ?? null)
        ->and($recorded['run_id'] ?? null)->toBe($result->runId)
        ->and($recorded['derivation']->modelName ?? null)->toBe('oss-omr')
        ->and($recorded['derivation']->modelVersion ?? null)->toBe('1.2.0')
        ->and($recorded['derivation']->representation['parts'][0]['id'] ?? null)->toBe('P1');
});

it('implements a real DownloadsArtifacts downloadArtifact for path and file inputs', function (): void {
    $connector = new ScoreTabConnector;
    $path = tempScoreTab('gp-fixture-bytes', 'gp5');

    $fromPath = $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'desktop:scores',
        artifactReference: 'file://'.$path,
        parameters: ['input' => 'path', 'path' => $path],
    ));
    $fromFile = $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'desktop:scores',
        artifactReference: 'memory://riff.tab#sha256:abc',
        parameters: [
            'input' => 'file',
            'name' => 'riff.tab',
            'contents_base64' => base64_encode("e|---0---\n"),
        ],
    ));

    expect($fromPath->contents)->toBe('gp-fixture-bytes')
        ->and($fromPath->mediaType)->toBe('application/x-guitar-pro')
        ->and($fromFile->contents)->toBe("e|---0---\n")
        ->and($fromFile->mediaType)->toBe('text/plain');

    @unlink($path);
});
