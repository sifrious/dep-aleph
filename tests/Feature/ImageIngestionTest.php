<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Image\BinaryImageMetadataInspector;
use Sifrious\Aleph\Connector\Image\ConvertImageFormat;
use Sifrious\Aleph\Connector\Image\ConvertImageFormatRequest;
use Sifrious\Aleph\Connector\Image\ImageArtifactSubmission;
use Sifrious\Aleph\Connector\Image\ImageCapturePayload;
use Sifrious\Aleph\Connector\Image\ImageClassificationObservation;
use Sifrious\Aleph\Connector\Image\ImageClassificationRecorder;
use Sifrious\Aleph\Connector\Image\ImageConversion;
use Sifrious\Aleph\Connector\Image\ImageConversionRecorder;
use Sifrious\Aleph\Connector\Image\ImageConverter;
use Sifrious\Aleph\Connector\Image\ImageConnector;
use Sifrious\Aleph\Connector\Image\ImageExifPresence;
use Sifrious\Aleph\Connector\Image\ImageObservationWriter;
use Sifrious\Aleph\Connector\Image\LaunchImageIngestion;
use Sifrious\Aleph\Connector\Image\LaunchImageIngestionRequest;
use Sifrious\Aleph\Connector\Image\LocalImageFilePayload;
use Sifrious\Aleph\Connector\Image\RecordImageClassification;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;
use Sifrious\Aleph\Tests\Fixtures\ImageFixture;

final class ImageNullManualDispatcher implements ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void {}
}

final class RecordingImageWriter implements ImageObservationWriter
{
    /** @var list<ImageArtifactSubmission> */
    public array $submissions = [];

    public function write(ImageArtifactSubmission $submission, string $attemptId): string
    {
        $this->submissions[] = $submission;

        return 'accepted:'.$submission->artifactReference;
    }
}

final class RecordingImageConversionRecorder implements ImageConversionRecorder
{
    /** @var list<array{observation_id: string, conversion: ImageConversion, source_checksum: string, run_id: string}> */
    public array $records = [];

    public function record(string $observationId, ImageConversion $conversion, string $sourceChecksum, string $runId): void
    {
        $this->records[] = [
            'observation_id' => $observationId,
            'conversion' => $conversion,
            'source_checksum' => $sourceChecksum,
            'run_id' => $runId,
        ];
    }
}

final class RecordingImageClassificationRecorder implements ImageClassificationRecorder
{
    /** @var list<ImageClassificationObservation> */
    public array $records = [];

    public function record(ImageClassificationObservation $observation): void
    {
        $this->records[] = $observation;
    }
}

final class FixtureImageConverter implements ImageConverter
{
    public function convert(string $contents, string $sourceMediaType, string $targetFormat): ImageConversion
    {
        $format = strtolower($targetFormat) === 'jpg' ? 'jpeg' : strtolower($targetFormat);
        $converted = 'converted-'.$format.'-'.hash('sha256', $contents);
        $mediaType = match ($format) {
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };

        return new ImageConversion(
            converterName: 'fixture-converter',
            converterVersion: '0.1.0',
            sourceMediaType: $sourceMediaType,
            targetMediaType: $mediaType,
            targetFormat: $format,
            contents: $converted,
            checksum: hash('sha256', $converted),
            bytes: strlen($converted),
            width: 1,
            height: 1,
        );
    }
}

/**
 * @return array{0: LaunchImageIngestion, 1: mixed, 2: mixed, 3: RecordingImageWriter}
 */
function imageLauncher(): array
{
    $registry = app(ConnectorRegistry::class);
    $connector = new ImageConnector(new BinaryImageMetadataInspector);
    $registry->register($connector);
    $installations = app(ConnectorInstallations::class);
    $firstInstallation = $installations->create(
        $connector,
        'Image files',
        owner: 'identity:user/mary',
    );
    $secondInstallation = $installations->create(
        $connector,
        'Image files backup',
        owner: 'identity:user/mary',
    );
    $launch = new LaunchIngestion(
        $registry,
        $installations,
        app(IngestionRuns::class),
        new ImageNullManualDispatcher,
    );
    $writer = new RecordingImageWriter;

    return [
        new LaunchImageIngestion(
            $launch,
            app(IngestionRuns::class),
            $registry,
            $writer,
            new BinaryImageMetadataInspector,
        ),
        $firstInstallation,
        $secondInstallation,
        $writer,
    ];
}

function tempImage(string $contents, string $extension = 'png'): string
{
    $path = sys_get_temp_dir().'/aleph-image-'.bin2hex(random_bytes(6)).'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

it('ingests a local image path through LaunchIngestion and stores metadata', function (): void {
    [$launcher, $installation, , $writer] = imageLauncher();
    $bytes = ImageFixture::png1x1();
    $path = tempImage($bytes, 'png');
    $request = LaunchImageIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:photos',
        path: $path,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:image/1'),
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
        ->and($submitted?->checksum)->toBe(hash('sha256', $bytes))
        ->and($submitted?->mediaType)->toBe('image/png')
        ->and($submitted?->image->width)->toBe(1)
        ->and($submitted?->image->height)->toBe(1)
        ->and($submitted?->image->colorSpace)->toBe('rgb')
        ->and($submitted?->image->modifiedAt)->not->toBeNull()
        ->and($submitted?->image->exifPresence)->toBe(ImageExifPresence::Missing)
        ->and($submitted?->image->toArray()['exif']['presence'] ?? null)->toBe('missing');

    @unlink($path);
});

it('ingests upload and capture inputs without inventing screenshot session metadata', function (): void {
    [$launcher, $installation, , $writer] = imageLauncher();
    $bytes = ImageFixture::png1x1();
    $capturedAt = new DateTimeImmutable('2026-08-29T18:00:00+00:00');

    $upload = $launcher->launch(LaunchImageIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:photos',
        file: new LocalImageFilePayload('upload.png', $bytes, 'image/png'),
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:image/2a'),
    ));
    $capture = $launcher->launch(LaunchImageIngestionRequest::fromCapture(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:photos',
        capture: new ImageCapturePayload('capture.png', $bytes."\x00", $capturedAt, 'image/png'),
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:image/2b'),
    ));

    expect($upload->replayed)->toBeFalse()
        ->and($capture->replayed)->toBeFalse()
        ->and($writer->submissions)->toHaveCount(2)
        ->and($writer->submissions[0]->metadata['input'] ?? null)->toBe('file')
        ->and($writer->submissions[1]->metadata['input'] ?? null)->toBe('capture')
        ->and($writer->submissions[1]->image->capturedAt)->toBe($capturedAt->format(DATE_ATOM))
        ->and($writer->submissions[1]->metadata)->not->toHaveKey('window')
        ->and($writer->submissions[1]->metadata)->not->toHaveKey('session');
});

it('returns the original run for duplicate content hash on the same installation', function (): void {
    [$launcher, $installation, , $writer] = imageLauncher();
    $bytes = ImageFixture::png1x1();
    $path = tempImage($bytes, 'png');
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:image/3');
    $request = LaunchImageIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:photos',
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

it('scopes image idempotency to the source installation', function (): void {
    [$launcher, $installationA, $installationB, $writer] = imageLauncher();
    $payload = new LocalImageFilePayload('shared.png', ImageFixture::png1x1(), 'image/png');
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:image/4');

    $first = $launcher->launch(LaunchImageIngestionRequest::fromFile(
        sourceInstallationId: $installationA->id,
        sourceReference: 'desktop:photos:a',
        file: $payload,
        authorization: $authorization,
    ));
    $second = $launcher->launch(LaunchImageIngestionRequest::fromFile(
        sourceInstallationId: $installationB->id,
        sourceReference: 'desktop:photos:b',
        file: $payload,
        authorization: $authorization,
    ));

    expect($first->runId)->not->toBe($second->runId)
        ->and($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeFalse()
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(2)
        ->and($writer->submissions)->toHaveCount(2);
});

it('distinguishes missing EXIF from empty EXIF', function (): void {
    $inspector = new BinaryImageMetadataInspector;

    $missing = $inspector->inspect(ImageFixture::jpegWithoutExif(), 'image/jpeg');
    $empty = $inspector->inspect(ImageFixture::jpegEmptyExif(), 'image/jpeg');
    $present = $inspector->inspect(ImageFixture::jpegWithExifDate(), 'image/jpeg');

    expect($missing->exifPresence)->toBe(ImageExifPresence::Missing)
        ->and($missing->toArray()['exif']['presence'])->toBe('missing')
        ->and($missing->toArray()['exif']['fields'])->toBe([])
        ->and($empty->exifPresence)->toBe(ImageExifPresence::Empty)
        ->and($empty->toArray()['exif']['presence'])->toBe('empty')
        ->and($empty->toArray()['exif']['fields'])->toBe([])
        ->and($present->exifPresence)->toBe(ImageExifPresence::Present)
        ->and($present->exifFields['DateTimeOriginal'] ?? null)->toBe('2026:08:29 12:00:00')
        ->and($missing->exifPresence)->not->toBe($empty->exifPresence);
});

it('explicit convert produces a new version and leaves the original bytes untouched', function (): void {
    [$launcher, $installation, , $writer] = imageLauncher();
    $bytes = ImageFixture::png1x1();
    $result = $launcher->launch(LaunchImageIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:photos',
        file: new LocalImageFilePayload('source.png', $bytes, 'image/png'),
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:image/5'),
    ));
    $observationId = $result->acceptedReferences[0] ?? '';
    $conversions = new RecordingImageConversionRecorder;
    $convert = new ConvertImageFormat(new FixtureImageConverter, $conversions);

    $first = $convert->convert(new ConvertImageFormatRequest(
        observationId: $observationId,
        runId: $result->runId,
        sourceContents: $bytes,
        sourceMediaType: 'image/png',
        targetFormat: 'webp',
    ));
    $second = $convert->convert(new ConvertImageFormatRequest(
        observationId: $observationId,
        runId: $result->runId,
        sourceContents: $bytes,
        sourceMediaType: 'image/png',
        targetFormat: 'jpeg',
    ));

    expect($writer->submissions)->toHaveCount(1)
        ->and($writer->submissions[0]->contents)->toBe($bytes)
        ->and($conversions->records)->toHaveCount(2)
        ->and($first->conversion->targetFormat)->toBe('webp')
        ->and($second->conversion->targetFormat)->toBe('jpeg')
        ->and($first->conversion->contents)->not->toBe($bytes)
        ->and($second->conversion->contents)->not->toBe($first->conversion->contents)
        ->and($conversions->records[0]['conversion']->converterName)->toBe('fixture-converter')
        ->and($conversions->records[0]['conversion']->converterVersion)->toBe('0.1.0')
        ->and($conversions->records[0]['source_checksum'])->toBe(hash('sha256', $bytes));
});

it('stores an outsourced classification result without classifying in-package', function (): void {
    $recorder = new RecordingImageClassificationRecorder;
    $service = new RecordImageClassification($recorder);

    $service->record(new ImageClassificationObservation(
        observationId: 'accepted:memory://photo.png',
        classifierName: 'external-vision',
        classifierVersion: '2026.8',
        labels: ['scene' => 'desk', 'objects' => ['mug']],
        runId: 'run-1',
        provenance: ['provider' => 'outsourced'],
    ));

    expect($recorder->records)->toHaveCount(1)
        ->and($recorder->records[0]->classifierName)->toBe('external-vision')
        ->and($recorder->records[0]->labels['scene'] ?? null)->toBe('desk')
        ->and($recorder->records[0]->toExtractionResult()['kind'] ?? null)->toBe('image_classification_observation');
});

it('implements a real DownloadsArtifacts downloadArtifact for path, file, and capture', function (): void {
    $connector = new ImageConnector;
    $bytes = ImageFixture::png1x1();
    $path = tempImage($bytes, 'png');

    $fromPath = $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'desktop:photos',
        artifactReference: 'file://'.$path,
        parameters: ['input' => 'path', 'path' => $path],
    ));
    $fromFile = $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'desktop:photos',
        artifactReference: 'memory://upload.png#sha256:abc',
        parameters: [
            'input' => 'file',
            'name' => 'upload.png',
            'contents_base64' => base64_encode($bytes),
            'media_type' => 'image/png',
        ],
    ));
    $fromCapture = $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'desktop:photos',
        artifactReference: 'capture://shot.png#sha256:abc',
        parameters: [
            'input' => 'capture',
            'name' => 'shot.png',
            'contents_base64' => base64_encode($bytes),
            'media_type' => 'image/png',
            'captured_at' => '2026-08-29T18:00:00+00:00',
        ],
    ));

    expect($fromPath->contents)->toBe($bytes)
        ->and($fromPath->mediaType)->toBe('image/png')
        ->and($fromFile->contents)->toBe($bytes)
        ->and($fromCapture->metadata['input'] ?? null)->toBe('capture')
        ->and($fromCapture->metadata['image']['exif']['presence'] ?? null)->toBe('missing');

    @unlink($path);
});

it('rejects non-image media types', function (): void {
    $connector = new ImageConnector;

    $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'desktop:photos',
        artifactReference: 'memory://notes.txt#sha256:abc',
        parameters: [
            'input' => 'file',
            'name' => 'notes.txt',
            'contents_base64' => base64_encode('not-an-image'),
            'media_type' => 'text/plain',
        ],
    ));
})->throws(InvalidArgumentException::class, 'Image ingestion accepts image media types only.');
