<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Handwriting\AbsentHandwritingLocalOcrModel;
use Sifrious\Aleph\Connector\Handwriting\HandwritingArtifactSubmission;
use Sifrious\Aleph\Connector\Handwriting\HandwritingConnector;
use Sifrious\Aleph\Connector\Handwriting\HandwritingLocalOcrModel;
use Sifrious\Aleph\Connector\Handwriting\HandwritingObservationWriter;
use Sifrious\Aleph\Connector\Handwriting\HandwritingOcrDerivation;
use Sifrious\Aleph\Connector\Handwriting\HandwritingOcrDerivationRecorder;
use Sifrious\Aleph\Connector\Handwriting\LaunchHandwritingIngestion;
use Sifrious\Aleph\Connector\Handwriting\LaunchHandwritingIngestionRequest;
use Sifrious\Aleph\Connector\Handwriting\LocalHandwritingFilePayload;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;

final class HandwritingNullManualDispatcher implements ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void {}
}

final class RecordingHandwritingWriter implements HandwritingObservationWriter
{
    /** @var list<HandwritingArtifactSubmission> */
    public array $submissions = [];

    public function write(HandwritingArtifactSubmission $submission, string $attemptId): string
    {
        $this->submissions[] = $submission;

        return 'accepted:'.$submission->artifactReference;
    }
}

final class RecordingHandwritingOcrDerivationRecorder implements HandwritingOcrDerivationRecorder
{
    /** @var list<array{observation_id: string, derivation: HandwritingOcrDerivation, run_id: string}> */
    public array $records = [];

    public function record(string $observationId, HandwritingOcrDerivation $derivation, string $runId): void
    {
        $this->records[] = [
            'observation_id' => $observationId,
            'derivation' => $derivation,
            'run_id' => $runId,
        ];
    }
}

final class FixtureHandwritingLocalOcrModel implements HandwritingLocalOcrModel
{
    public function __construct(
        private readonly bool $available = true,
        private readonly ?HandwritingOcrDerivation $derivation = null,
        private readonly bool $throw = false,
    ) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function recognize(string $contents, string $mediaType, string $name): ?HandwritingOcrDerivation
    {
        if ($this->throw) {
            throw new RuntimeException('local handwriting OCR model failed');
        }

        return $this->derivation ?? new HandwritingOcrDerivation(
            'fixture-oss-handwriting-ocr',
            '0.1.0',
            'hello from fixture',
            [
                [
                    'text' => 'hello',
                    'x' => 1,
                    'y' => 2,
                    'width' => 40,
                    'height' => 12,
                ],
            ],
        );
    }
}

/**
 * @return array{0: LaunchHandwritingIngestion, 1: mixed, 2: mixed, 3: RecordingHandwritingWriter, 4: RecordingHandwritingOcrDerivationRecorder}
 */
function handwritingLauncher(
    ?HandwritingLocalOcrModel $model = null,
    ?HandwritingObservationWriter $writer = null,
    ?HandwritingOcrDerivationRecorder $derivations = null,
): array {
    $registry = app(ConnectorRegistry::class);
    $connector = new HandwritingConnector;
    $registry->register($connector);
    $installations = app(ConnectorInstallations::class);
    $firstInstallation = $installations->create(
        $connector,
        'Handwriting images',
        owner: 'identity:user/mary',
    );
    $secondInstallation = $installations->create(
        $connector,
        'Handwriting images backup',
        owner: 'identity:user/mary',
    );
    $launch = new LaunchIngestion(
        $registry,
        $installations,
        app(IngestionRuns::class),
        new HandwritingNullManualDispatcher,
    );
    $writer ??= new RecordingHandwritingWriter;
    $derivations ??= new RecordingHandwritingOcrDerivationRecorder;
    $model ??= new AbsentHandwritingLocalOcrModel;

    return [
        new LaunchHandwritingIngestion(
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

function tempHandwriting(string $contents, string $extension = 'png'): string
{
    $path = sys_get_temp_dir().'/aleph-handwriting-'.bin2hex(random_bytes(6)).'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

it('ingests a local handwritten image path through launch ingestion and stores one artifact', function (): void {
    [$launcher, $installation, , $writer, $derivations] = handwritingLauncher();
    $path = tempHandwriting('fake-handwritten-png-bytes');
    $request = LaunchHandwritingIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:notes',
        path: $path,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:handwriting/1'),
    );

    $result = $launcher->launch($request);
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;

    expect($result->replayed)->toBeFalse()
        ->and($run)->not->toBeNull()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->acceptedReferences)->toBe($result->acceptedReferences)
        ->and($run?->stats['ocr_skipped'] ?? null)->toBe(1)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted?->artifactReference)->toBe('file://'.realpath($path))
        ->and($submitted?->checksum)->toBe(hash('sha256', 'fake-handwritten-png-bytes'))
        ->and($submitted?->mediaType)->toBe('image/png')
        ->and($submitted?->metadata['format'] ?? null)->toBe('handwritten_image')
        ->and($derivations->records)->toBe([]);

    @unlink($path);
});

it('ingests handwritten image file payloads without a local OCR model', function (): void {
    [$launcher, $installation, , $writer, $derivations] = handwritingLauncher();
    $jpeg = new LocalHandwritingFilePayload('page.jpg', 'fake-jpeg-bytes', 'image/jpeg');
    $webp = new LocalHandwritingFilePayload('sketch.webp', 'fake-webp-bytes', 'image/webp');

    $jpegResult = $launcher->launch(LaunchHandwritingIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:notes',
        file: $jpeg,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:handwriting/2a'),
    ));
    $webpResult = $launcher->launch(LaunchHandwritingIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:notes',
        file: $webp,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:handwriting/2b'),
    ));
    $jpegRun = app(IngestionRuns::class)->find($jpegResult->runId);

    expect($jpegResult->replayed)->toBeFalse()
        ->and($webpResult->replayed)->toBeFalse()
        ->and($writer->submissions)->toHaveCount(2)
        ->and($writer->submissions[0]->mediaType)->toBe('image/jpeg')
        ->and($writer->submissions[1]->mediaType)->toBe('image/webp')
        ->and($jpegRun?->stats['ocr_skipped'] ?? null)->toBe(1)
        ->and($derivations->records)->toBe([]);
});

it('returns the original run and avoids duplicate artifact writes for the same image', function (): void {
    [$launcher, $installation, , $writer] = handwritingLauncher();
    $path = tempHandwriting('same-handwriting-bytes', 'jpg');
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:handwriting/3');
    $request = LaunchHandwritingIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:notes',
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

it('scopes handwriting idempotency to the source installation', function (): void {
    [$launcher, $installationA, $installationB, $writer] = handwritingLauncher();
    $payload = new LocalHandwritingFilePayload('shared.png', 'identical-handwriting-bytes', 'image/png');
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:handwriting/4');

    $first = $launcher->launch(LaunchHandwritingIngestionRequest::fromFile(
        sourceInstallationId: $installationA->id,
        sourceReference: 'desktop:notes:a',
        file: $payload,
        authorization: $authorization,
    ));
    $second = $launcher->launch(LaunchHandwritingIngestionRequest::fromFile(
        sourceInstallationId: $installationB->id,
        sourceReference: 'desktop:notes:b',
        file: $payload,
        authorization: $authorization,
    ));

    expect($first->runId)->not->toBe($second->runId)
        ->and($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeFalse()
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(2)
        ->and($writer->submissions)->toHaveCount(2);
});

it('stores the image and records OCR skipped when the free local model is absent', function (): void {
    [$launcher, $installation, , $writer, $derivations] = handwritingLauncher(new AbsentHandwritingLocalOcrModel);
    $payload = new LocalHandwritingFilePayload('missing-model.png', 'png-fixture-bytes', 'image/png');

    $result = $launcher->launch(LaunchHandwritingIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:notes',
        file: $payload,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:handwriting/5'),
    ));
    $run = app(IngestionRuns::class)->find($result->runId);

    expect($result->replayed)->toBeFalse()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->stats['ocr_skipped'] ?? null)->toBe(1)
        ->and($run?->stats)->not->toHaveKey('ocr_derived')
        ->and($writer->submissions)->toHaveCount(1)
        ->and($writer->submissions[0]->contents)->toBe('png-fixture-bytes')
        ->and($derivations->records)->toBe([]);
});

it('records OCR skipped without failing ingest when the local model throws', function (): void {
    $derivations = new RecordingHandwritingOcrDerivationRecorder;
    [$launcher, $installation, , $writer] = handwritingLauncher(
        new FixtureHandwritingLocalOcrModel(throw: true),
        derivations: $derivations,
    );
    $payload = new LocalHandwritingFilePayload('broken-model.png', 'png-bytes', 'image/png');

    $result = $launcher->launch(LaunchHandwritingIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:notes',
        file: $payload,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:handwriting/6'),
    ));
    $run = app(IngestionRuns::class)->find($result->runId);

    expect($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->stats['ocr_skipped'] ?? null)->toBe(1)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($derivations->records)->toBe([]);
});

it('stores a present free local OCR derivation as a new version without overwriting raw image bytes', function (): void {
    $raw = 'authoritative-handwritten-image-bytes';
    $derivations = new RecordingHandwritingOcrDerivationRecorder;
    $model = new FixtureHandwritingLocalOcrModel(
        derivation: new HandwritingOcrDerivation('oss-handwriting-ocr', '1.0.0', 'meeting notes', [
            ['text' => 'meeting', 'x' => 10, 'y' => 20, 'width' => 80, 'height' => 14],
            ['text' => 'notes', 'x' => 10, 'y' => 40, 'width' => 50, 'height' => 14],
        ]),
    );
    [$launcher, $installation, , $writer] = handwritingLauncher($model, derivations: $derivations);

    $result = $launcher->launch(LaunchHandwritingIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:notes',
        file: new LocalHandwritingFilePayload('notes.png', $raw, 'image/png'),
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:handwriting/7'),
    ));
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;
    $recorded = $derivations->records[0] ?? null;

    expect($result->replayed)->toBeFalse()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->stats['ocr_derived'] ?? null)->toBe(1)
        ->and($run?->stats)->not->toHaveKey('ocr_skipped')
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted?->contents)->toBe($raw)
        ->and($submitted?->checksum)->toBe(hash('sha256', $raw))
        ->and($derivations->records)->toHaveCount(1)
        ->and($recorded['observation_id'] ?? null)->toBe($result->acceptedReferences[0] ?? null)
        ->and($recorded['run_id'] ?? null)->toBe($result->runId)
        ->and($recorded['derivation']->modelName ?? null)->toBe('oss-handwriting-ocr')
        ->and($recorded['derivation']->modelVersion ?? null)->toBe('1.0.0')
        ->and($recorded['derivation']->text ?? null)->toBe('meeting notes')
        ->and($recorded['derivation']->boundingBoxes[0]['text'] ?? null)->toBe('meeting')
        ->and($recorded['derivation']->representation()['kind'] ?? null)->toBe('handwriting_ocr');
});

it('implements a real DownloadsArtifacts downloadArtifact for path and file inputs', function (): void {
    $connector = new HandwritingConnector;
    $path = tempHandwriting('handwriting-fixture-bytes', 'png');

    $fromPath = $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'desktop:notes',
        artifactReference: 'file://'.$path,
        parameters: ['input' => 'path', 'path' => $path],
    ));
    $fromFile = $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'desktop:notes',
        artifactReference: 'memory://page.jpg#sha256:abc',
        parameters: [
            'input' => 'file',
            'name' => 'page.jpg',
            'contents_base64' => base64_encode('jpeg-fixture'),
        ],
    ));

    expect($fromPath->contents)->toBe('handwriting-fixture-bytes')
        ->and($fromPath->mediaType)->toBe('image/png')
        ->and($fromFile->contents)->toBe('jpeg-fixture')
        ->and($fromFile->mediaType)->toBe('image/jpeg');

    @unlink($path);
});

it('rejects non-image handwriting submissions', function (): void {
    $connector = new HandwritingConnector;

    expect(fn () => $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'desktop:notes',
        artifactReference: 'memory://notes.pdf#sha256:abc',
        parameters: [
            'input' => 'file',
            'name' => 'notes.pdf',
            'contents_base64' => base64_encode('%PDF-fixture'),
            'media_type' => 'application/pdf',
        ],
    )))->toThrow(InvalidArgumentException::class, 'Handwriting ingestion accepts image media types only.');
});
