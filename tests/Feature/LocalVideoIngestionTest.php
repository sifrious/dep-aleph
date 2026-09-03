<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Connector\VideoFile\AbsentPythonVideoFileAdapter;
use Sifrious\Aleph\Connector\VideoFile\LaunchLocalVideoIngestion;
use Sifrious\Aleph\Connector\VideoFile\LaunchLocalVideoIngestionRequest;
use Sifrious\Aleph\Connector\VideoFile\LocalVideoFilePayload;
use Sifrious\Aleph\Connector\VideoFile\PythonVideoFileAdapter;
use Sifrious\Aleph\Connector\VideoFile\VideoFileArtifactSubmission;
use Sifrious\Aleph\Connector\VideoFile\VideoFileConnector;
use Sifrious\Aleph\Connector\VideoFile\VideoFileEnvelopeDocument;
use Sifrious\Aleph\Connector\VideoFile\VideoFileObservationWriter;
use Sifrious\Aleph\Ingestion\IngestAdapterRegistry;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\IngestLanguage;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\LaunchRejected;
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

    /** @var list<array<string, mixed>> */
    public array $documents = [];

    public function write(VideoFileArtifactSubmission $submission, string $attemptId): string
    {
        $this->submissions[] = $submission;
        $this->documents[] = VideoFileEnvelopeDocument::fromSubmission($submission, $submission->language);

        return 'accepted:'.$submission->artifactReference;
    }

    public function writeEnvelopeDocument(array $document, string $attemptId): string
    {
        $this->documents[] = $document;
        $payload = base64_decode((string) ($document['payload_base64'] ?? ''), true);
        $provenance = is_array($document['provenance'] ?? null) ? $document['provenance'] : [];
        $details = is_array($provenance['details'] ?? null) ? $provenance['details'] : [];

        $this->submissions[] = new VideoFileArtifactSubmission(
            sourceReference: (string) ($document['source_reference'] ?? ''),
            sourceInstallationId: (string) ($provenance['installation'] ?? ''),
            runId: (string) ($provenance['run'] ?? ''),
            artifactReference: (string) ($document['resource_reference'] ?? ''),
            mediaType: (string) ($document['content_type'] ?? 'application/octet-stream'),
            contents: is_string($payload) ? $payload : '',
            checksum: (string) ($document['payload_sha256'] ?? ''),
            bytes: (int) ($document['payload_bytes'] ?? 0),
            metadata: [],
            language: (string) ($details['language'] ?? 'python'),
        );

        return 'accepted:'.(string) ($document['resource_reference'] ?? 'document');
    }
}

final class FakePythonVideoFileAdapter implements PythonVideoFileAdapter
{
    public bool $available = true;

    /** @var list<array<string, mixed>> */
    public array $emitted = [];

    public function available(): bool
    {
        return $this->available;
    }

    public function emitEnvelope(
        string $sourceReference,
        string $sourceInstallationId,
        string $runId,
        string $artifactReference,
        string $mediaType,
        string $contents,
        array $metadata,
    ): array {
        if (! $this->available) {
            throw new LaunchRejected(
                'language_unavailable',
                'Ingest language [python] is not available for capability [video-file].',
            );
        }

        $document = VideoFileEnvelopeDocument::build(
            sourceReference: $sourceReference,
            sourceInstallationId: $sourceInstallationId,
            runId: $runId,
            artifactReference: $artifactReference,
            mediaType: $mediaType,
            contents: $contents,
            checksum: hash('sha256', $contents),
            bytes: strlen($contents),
            metadata: $metadata,
            language: IngestLanguage::Python->value,
        );
        $this->emitted[] = $document;

        return $document;
    }
}

/**
 * @return array{0: LaunchLocalVideoIngestion, 1: mixed, 2: RecordingVideoFileWriter, 3: IngestAdapterRegistry, 4: FakePythonVideoFileAdapter|AbsentPythonVideoFileAdapter}
 */
function localVideoLauncher(?PythonVideoFileAdapter $python = null, bool $registerPython = true): array
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
    $python ??= new FakePythonVideoFileAdapter;
    $languages = new IngestAdapterRegistry;
    $languages->register(VideoFileEnvelopeDocument::CAPABILITY, IngestLanguage::Php, true);
    $languages->register(
        VideoFileEnvelopeDocument::CAPABILITY,
        IngestLanguage::Python,
        $registerPython && $python->available(),
    );

    return [
        new LaunchLocalVideoIngestion($launch, app(IngestionRuns::class), $registry, $writer, $languages, $python),
        $installation,
        $writer,
        $languages,
        $python,
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
        ->and($run?->parameters['language'] ?? null)->toBe('php')
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted)->not->toBeNull()
        ->and($submitted?->artifactReference)->toBe('file://'.realpath($path))
        ->and($submitted?->checksum)->toBe(hash('sha256', 'fixture-video-bytes'))
        ->and($submitted?->mediaType)->toBe('video/mp4')
        ->and($submitted?->language)->toBe('php');

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

it('lists php and python for the local video capability and routes language=php', function (): void {
    [$launcher, $installation, $writer, $languages] = localVideoLauncher();

    expect($languages->languages(VideoFileEnvelopeDocument::CAPABILITY))
        ->toBe([IngestLanguage::Php, IngestLanguage::Python]);

    $result = $launcher->launch(LaunchLocalVideoIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:capture:main',
        file: new LocalVideoFilePayload('a.mp4', 'php-bytes', 'video/mp4'),
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:video-file/php'),
        language: IngestLanguage::Php,
    ));
    $run = app(IngestionRuns::class)->find($result->runId);

    expect($run?->parameters['language'] ?? null)->toBe('php')
        ->and($writer->submissions[0]?->language)->toBe('php')
        ->and($writer->documents[0]['source_name'] ?? null)->toBe('local-video-file');
});

it('routes language=python through the twin and records language provenance on the run', function (): void {
    $python = new FakePythonVideoFileAdapter;
    [$launcher, $installation, $writer] = localVideoLauncher($python);

    $result = $launcher->launch(LaunchLocalVideoIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:capture:main',
        file: new LocalVideoFilePayload('b.mp4', 'python-bytes', 'video/mp4'),
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:video-file/python'),
        language: IngestLanguage::Python,
    ));
    $run = app(IngestionRuns::class)->find($result->runId);

    expect($result->replayed)->toBeFalse()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->parameters['language'] ?? null)->toBe('python')
        ->and($python->emitted)->toHaveCount(1)
        ->and($writer->documents)->toHaveCount(1)
        ->and($writer->documents[0]['provenance']['details']['language'] ?? null)->toBe('python')
        ->and($writer->submissions[0]?->language)->toBe('python');
});

it('resolves language=any to an available concrete language', function (): void {
    [$launcher, $installation, $writer] = localVideoLauncher();

    $result = $launcher->launch(LaunchLocalVideoIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:capture:main',
        file: new LocalVideoFilePayload('c.mp4', 'any-bytes', 'video/mp4'),
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:video-file/any'),
        language: IngestLanguage::Any,
    ));
    $run = app(IngestionRuns::class)->find($result->runId);

    expect($run?->parameters['language'] ?? null)->toBe('php')
        ->and($writer->submissions[0]?->language)->toBe('php');
});

it('refuses explicitly when the requested language is unavailable', function (): void {
    $registry = app(ConnectorRegistry::class);
    $connector = new VideoFileConnector;
    $registry->register($connector);
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'Local video files',
        owner: 'identity:user/mary',
    );
    $languages = new IngestAdapterRegistry;
    $languages->register(VideoFileEnvelopeDocument::CAPABILITY, IngestLanguage::Php, true);
    $languages->register(VideoFileEnvelopeDocument::CAPABILITY, IngestLanguage::Python, false);
    $launcher = new LaunchLocalVideoIngestion(
        new LaunchIngestion(
            $registry,
            app(ConnectorInstallations::class),
            app(IngestionRuns::class),
            new LocalVideoNullManualDispatcher,
        ),
        app(IngestionRuns::class),
        $registry,
        new RecordingVideoFileWriter,
        $languages,
        new AbsentPythonVideoFileAdapter,
    );

    try {
        $launcher->launch(LaunchLocalVideoIngestionRequest::fromFile(
            sourceInstallationId: $installation->id,
            sourceReference: 'desktop:capture:main',
            file: new LocalVideoFilePayload('d.mp4', 'missing-python', 'video/mp4'),
            authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:video-file/missing'),
            language: IngestLanguage::Python,
        ));
        expect(false)->toBeTrue('expected LaunchRejected');
    } catch (LaunchRejected $rejected) {
        expect($rejected->reason)->toBe('language_unavailable')
            ->and($rejected->getMessage())->toContain('python');
    }
});

it('still launches with php when python is not installed', function (): void {
    $registry = app(ConnectorRegistry::class);
    $connector = new VideoFileConnector;
    $registry->register($connector);
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'Local video files',
        owner: 'identity:user/mary',
    );
    $writer = new RecordingVideoFileWriter;
    $languages = new IngestAdapterRegistry;
    $languages->register(VideoFileEnvelopeDocument::CAPABILITY, IngestLanguage::Php, true);
    $languages->register(VideoFileEnvelopeDocument::CAPABILITY, IngestLanguage::Python, false);
    $launcher = new LaunchLocalVideoIngestion(
        new LaunchIngestion(
            $registry,
            app(ConnectorInstallations::class),
            app(IngestionRuns::class),
            new LocalVideoNullManualDispatcher,
        ),
        app(IngestionRuns::class),
        $registry,
        $writer,
        $languages,
        new AbsentPythonVideoFileAdapter,
    );

    $result = $launcher->launch(LaunchLocalVideoIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:capture:main',
        file: new LocalVideoFilePayload('e.mp4', 'php-only-bytes', 'video/mp4'),
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:video-file/php-only'),
        language: IngestLanguage::Any,
    ));

    expect($result->replayed)->toBeFalse()
        ->and($writer->submissions)->toHaveCount(1)
        ->and($writer->submissions[0]?->language)->toBe('php')
        ->and($languages->languages(VideoFileEnvelopeDocument::CAPABILITY))
        ->toBe([IngestLanguage::Php, IngestLanguage::Python]);
});

it('compares php and python envelope shapes for the same fixture bytes', function (): void {
    $contents = 'shared-fixture-video-bytes';
    $artifact = 'memory://shared.mp4#sha256:'.hash('sha256', $contents);
    $phpDocument = VideoFileEnvelopeDocument::build(
        sourceReference: 'desktop:capture:main',
        sourceInstallationId: 'install:1',
        runId: 'run:php',
        artifactReference: $artifact,
        mediaType: 'video/mp4',
        contents: $contents,
        checksum: hash('sha256', $contents),
        bytes: strlen($contents),
        metadata: ['input' => 'file', 'name' => 'shared.mp4'],
        language: 'php',
    );

    $python = new FakePythonVideoFileAdapter;
    $pythonDocument = $python->emitEnvelope(
        sourceReference: 'desktop:capture:main',
        sourceInstallationId: 'install:1',
        runId: 'run:python',
        artifactReference: $artifact,
        mediaType: 'video/mp4',
        contents: $contents,
        metadata: ['input' => 'file', 'name' => 'shared.mp4'],
    );

    expect(VideoFileEnvelopeDocument::comparableShape($phpDocument))
        ->toBe(VideoFileEnvelopeDocument::comparableShape($pythonDocument));
});

it('emits the same comparable envelope shape from the sibling python twin script', function (): void {
    $script = dirname(__DIR__, 2).'/python/video_file/ingest.py';

    if (! is_file($script)) {
        $script = base_path('python/video_file/ingest.py');
    }

    expect(is_file($script))->toBeTrue();

    $contents = 'sibling-twin-fixture-bytes';
    $artifact = 'memory://twin.mp4#sha256:'.hash('sha256', $contents);
    $request = json_encode([
        'source_reference' => 'desktop:capture:main',
        'source_installation_id' => 'install:twin',
        'run_id' => 'run:twin-python',
        'artifact_reference' => $artifact,
        'media_type' => 'video/mp4',
        'contents_base64' => base64_encode($contents),
        'metadata' => ['input' => 'file', 'name' => 'twin.mp4'],
    ], JSON_THROW_ON_ERROR);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open('python3 '.escapeshellarg($script), $descriptors, $pipes, dirname($script, 2));
    expect(is_resource($process))->toBeTrue();
    fwrite($pipes[0], $request);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0)
        ->and(trim((string) $stderr))->toBe('');
    $pythonDocument = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    $phpDocument = VideoFileEnvelopeDocument::build(
        sourceReference: 'desktop:capture:main',
        sourceInstallationId: 'install:twin',
        runId: 'run:twin-php',
        artifactReference: $artifact,
        mediaType: 'video/mp4',
        contents: $contents,
        checksum: hash('sha256', $contents),
        bytes: strlen($contents),
        metadata: ['input' => 'file', 'name' => 'twin.mp4'],
        language: 'php',
    );

    expect(VideoFileEnvelopeDocument::comparableShape($phpDocument))
        ->toBe(VideoFileEnvelopeDocument::comparableShape($pythonDocument));
});

it('downloads artifacts for path and file inputs when DownloadsArtifacts is advertised', function (): void {
    $connector = new VideoFileConnector;
    $path = tempVideo('download-path-bytes');
    $fromPath = $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'desktop:capture:main',
        artifactReference: 'file://'.$path,
        parameters: ['input' => 'path', 'path' => $path],
    ));
    $fromFile = $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'desktop:capture:main',
        artifactReference: 'memory://clip.mp4',
        parameters: [
            'input' => 'file',
            'name' => 'clip.mp4',
            'contents_base64' => base64_encode('download-file-bytes'),
            'media_type' => 'video/mp4',
        ],
    ));

    expect($fromPath->contents)->toBe('download-path-bytes')
        ->and($fromFile->contents)->toBe('download-file-bytes')
        ->and($fromFile->mediaType)->toBe('video/mp4');

    @unlink($path);
});
