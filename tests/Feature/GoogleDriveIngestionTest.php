<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\GoogleDrive\AbsentDocumentFormatHandoff;
use Sifrious\Aleph\Connector\GoogleDrive\DocumentFormatHandoff;
use Sifrious\Aleph\Connector\GoogleDrive\DocumentFormatHandoffRequest;
use Sifrious\Aleph\Connector\GoogleDrive\DocumentFormatHandoffResult;
use Sifrious\Aleph\Connector\GoogleDrive\FunesDocumentFormatHandoff;
use Sifrious\Aleph\Connector\GoogleDrive\GoogleDriveArtifactSubmission;
use Sifrious\Aleph\Connector\GoogleDrive\GoogleDriveConnector;
use Sifrious\Aleph\Connector\GoogleDrive\GoogleDriveExportDenied;
use Sifrious\Aleph\Connector\GoogleDrive\GoogleDriveExportPlan;
use Sifrious\Aleph\Connector\GoogleDrive\GoogleDriveExportResult;
use Sifrious\Aleph\Connector\GoogleDrive\GoogleDriveFileClient;
use Sifrious\Aleph\Connector\GoogleDrive\GoogleDriveFileMetadata;
use Sifrious\Aleph\Connector\GoogleDrive\GoogleDriveObservationWriter;
use Sifrious\Aleph\Connector\GoogleDrive\LaunchGoogleDriveIngestion;
use Sifrious\Aleph\Connector\GoogleDrive\LaunchGoogleDriveIngestionRequest;
use Sifrious\Aleph\Connector\GoogleDrive\MissingGoogleDriveCredentials;
use Sifrious\Aleph\Connector\GoogleDrive\NullGoogleDriveFileClient;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;
use ZipArchive;

final class GoogleDriveNullManualDispatcher implements ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void {}
}

final class FixtureGoogleDriveFileClient implements GoogleDriveFileClient
{
    /** @var list<string> */
    public array $metadataCalls = [];

    /** @var list<array{0: string, 1: ?string}> */
    public array $exportCalls = [];

    /** @var array<string, GoogleDriveFileMetadata> */
    private array $files;

    /** @var array<string, GoogleDriveExportResult|Throwable> */
    private array $exports;

    /**
     * @param  array<string, GoogleDriveFileMetadata>  $files
     * @param  array<string, GoogleDriveExportResult|Throwable>  $exports
     */
    public function __construct(array $files, array $exports)
    {
        $this->files = $files;
        $this->exports = $exports;
    }

    public function metadata(string $fileId): GoogleDriveFileMetadata
    {
        $this->metadataCalls[] = $fileId;

        return $this->files[$fileId] ?? throw new MissingGoogleDriveCredentials('Unknown fixture file id.');
    }

    public function exportOrDownload(string $fileId, ?string $preferredExtension = null): GoogleDriveExportResult
    {
        $this->exportCalls[] = [$fileId, $preferredExtension];
        $entry = $this->exports[$fileId] ?? throw new MissingGoogleDriveCredentials('Unknown fixture export id.');

        if ($entry instanceof Throwable) {
            throw $entry;
        }

        $plan = GoogleDriveExportPlan::for($entry->sourceMimeType, $preferredExtension);

        return new GoogleDriveExportResult(
            fileId: $entry->fileId,
            revisionId: $entry->revisionId,
            sourceMimeType: $entry->sourceMimeType,
            exportMediaType: $plan['media_type'],
            exportExtension: $plan['extension'],
            filename: pathinfo($entry->filename, PATHINFO_FILENAME).'.'.$plan['extension'],
            contents: $entry->contents,
            nativeGoogleFormat: $entry->nativeGoogleFormat,
            metadata: array_merge($entry->metadata, ['preferred_extension' => $preferredExtension]),
        );
    }
}

final class RecordingGoogleDriveWriter implements GoogleDriveObservationWriter
{
    /** @var list<GoogleDriveArtifactSubmission> */
    public array $submissions = [];

    public function write(GoogleDriveArtifactSubmission $submission, string $attemptId): string
    {
        $this->submissions[] = $submission;

        return 'accepted:'.$submission->artifactReference;
    }
}

final class RecordingDocumentFormatHandoff implements DocumentFormatHandoff
{
    /** @var list<DocumentFormatHandoffRequest> */
    public array $requests = [];

    public function __construct(private readonly bool $launchFormatRun = false) {}

    public function handOff(DocumentFormatHandoffRequest $request): DocumentFormatHandoffResult
    {
        $this->requests[] = $request;

        if ($this->launchFormatRun) {
            return DocumentFormatHandoffResult::launched('format-run:'.$request->checksum, [
                'formatter' => 'mme-777',
                'media_type' => $request->mediaType,
            ]);
        }

        return (new AbsentDocumentFormatHandoff)->handOff($request);
    }
}

/**
 * @return array{
 *     0: LaunchGoogleDriveIngestion,
 *     1: mixed,
 *     2: FixtureGoogleDriveFileClient,
 *     3: RecordingGoogleDriveWriter,
 *     4: RecordingDocumentFormatHandoff,
 *     5: GoogleDriveConnector
 * }
 */
function googleDriveLauncher(
    FixtureGoogleDriveFileClient $client,
    bool $launchFormatRun = false,
): array {
    $registry = app(ConnectorRegistry::class);
    $connector = new GoogleDriveConnector($client);
    $registry->register($connector);
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'Google Drive account',
        externalAccountId: 'google-drive:account:1',
    );
    $launch = new LaunchIngestion(
        $registry,
        app(ConnectorInstallations::class),
        app(IngestionRuns::class),
        new GoogleDriveNullManualDispatcher,
    );
    $writer = new RecordingGoogleDriveWriter;
    $handoff = new RecordingDocumentFormatHandoff($launchFormatRun);

    return [
        new LaunchGoogleDriveIngestion($launch, app(IngestionRuns::class), $registry, $client, $writer, $handoff),
        $installation,
        $client,
        $writer,
        $handoff,
        $connector,
    ];
}

function googleDriveAuthorization(string $suffix): LaunchAuthorization
{
    return LaunchAuthorization::granted('identity:user/mary', 'authorization:google-drive/'.$suffix);
}

function googleDriveDocFixture(): array
{
    $fileId = 'doc-file-1';
    $revision = 'rev-doc-1';
    $docx = 'PK-docx-fixture-bytes';

    return [
        $fileId,
        new FixtureGoogleDriveFileClient(
            [
                $fileId => new GoogleDriveFileMetadata($fileId, $revision, GoogleDriveExportPlan::DOCS_MIME, 'Quarterly Notes'),
            ],
            [
                $fileId => new GoogleDriveExportResult(
                    $fileId,
                    $revision,
                    GoogleDriveExportPlan::DOCS_MIME,
                    GoogleDriveExportPlan::DOCX,
                    'docx',
                    'Quarterly Notes.docx',
                    $docx,
                    true,
                ),
            ],
        ),
        $docx,
        $revision,
    ];
}

function googleDriveDocx(string ...$paragraphs): string
{
    $path = tempnam(sys_get_temp_dir(), 'aleph-docx-test-');

    if ($path === false) {
        throw new RuntimeException('Could not create the DOCX test fixture.');
    }

    $archive = new ZipArchive;

    if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not open the DOCX test fixture.');
    }

    $body = implode('', array_map(
        static fn (string $text): string => '<w:p><w:r><w:t>'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p>',
        $paragraphs,
    ));
    $archive->addFromString(
        'word/document.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        .$body
        .'</w:body></w:document>',
    );
    $archive->close();
    $contents = file_get_contents($path);
    unlink($path);

    if (! is_string($contents)) {
        throw new RuntimeException('Could not read the DOCX test fixture.');
    }

    return $contents;
}

/** @param array<string, string> $parts */
function googleDriveOfficeArchive(array $parts): string
{
    $path = tempnam(sys_get_temp_dir(), 'aleph-office-test-');

    if ($path === false) {
        throw new RuntimeException('Could not create the Office test fixture.');
    }

    $archive = new ZipArchive;

    if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not open the Office test fixture.');
    }

    foreach ($parts as $name => $contents) {
        $archive->addFromString($name, $contents);
    }

    $archive->close();
    $contents = file_get_contents($path);
    unlink($path);

    if (! is_string($contents)) {
        throw new RuntimeException('Could not read the Office test fixture.');
    }

    return $contents;
}

/**
 * @return array{LaunchGoogleDriveIngestion, mixed}
 */
function realGoogleDriveLauncher(FixtureGoogleDriveFileClient $client): array
{
    $registry = app(ConnectorRegistry::class);
    $connector = new GoogleDriveConnector($client);
    $registry->register($connector);
    $installation = app(ConnectorInstallations::class)->create(
        $connector,
        'Google Drive formatter account',
        externalAccountId: 'google-drive:formatter:1',
    );

    return [
        new LaunchGoogleDriveIngestion(
            new LaunchIngestion(
                $registry,
                app(ConnectorInstallations::class),
                app(IngestionRuns::class),
                new GoogleDriveNullManualDispatcher,
            ),
            app(IngestionRuns::class),
            $registry,
            $client,
            app(GoogleDriveObservationWriter::class),
            app(DocumentFormatHandoff::class),
        ),
        $installation,
    ];
}

it('ingests one Google Doc into interchange bytes plus a document format handoff', function (): void {
    [$fileId, $client, $docx, $revision] = googleDriveDocFixture();
    [$launcher, $installation, , $writer, $handoff] = googleDriveLauncher($client, launchFormatRun: true);

    $result = $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/main',
        fileId: $fileId,
        authorization: googleDriveAuthorization('1'),
    ));
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;
    $handed = $handoff->requests[0] ?? null;

    expect($result->replayed)->toBeFalse()
        ->and($result->fileId)->toBe($fileId)
        ->and($result->revisionId)->toBe($revision)
        ->and($result->exportMediaType)->toBe(GoogleDriveExportPlan::DOCX)
        ->and($result->exportExtension)->toBe('docx')
        ->and($result->formatHandoff['status'] ?? null)->toBe('launched')
        ->and($result->formatHandoff['format_run_id'] ?? null)->toBe('format-run:'.hash('sha256', $docx))
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->acceptedReferences)->toBe($result->acceptedReferences)
        ->and($submitted)->not->toBeNull()
        ->and($submitted?->contents)->toBe($docx)
        ->and($submitted?->mediaType)->toBe(GoogleDriveExportPlan::DOCX)
        ->and($submitted?->nativeGoogleFormat)->toBeTrue()
        ->and($submitted?->checksum)->toBe(hash('sha256', $docx))
        ->and($handed?->mediaType)->toBe(GoogleDriveExportPlan::DOCX)
        ->and($handed?->contents)->toBe($docx);
});

it('stores a PDF sitting in Drive as a PDF and routes without re-wrapping', function (): void {
    $fileId = 'pdf-file-1';
    $revision = 'rev-pdf-1';
    $pdf = '%PDF-1.4 drive-fixture-bytes';
    $client = new FixtureGoogleDriveFileClient(
        [
            $fileId => new GoogleDriveFileMetadata($fileId, $revision, 'application/pdf', 'invoice.pdf'),
        ],
        [
            $fileId => new GoogleDriveExportResult(
                $fileId,
                $revision,
                'application/pdf',
                'application/pdf',
                'pdf',
                'invoice.pdf',
                $pdf,
                false,
            ),
        ],
    );
    [$launcher, $installation, , $writer, $handoff] = googleDriveLauncher($client);

    $result = $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/main',
        fileId: $fileId,
        authorization: googleDriveAuthorization('2'),
    ));

    expect($result->exportMediaType)->toBe('application/pdf')
        ->and($result->exportExtension)->toBe('pdf')
        ->and($writer->submissions[0]->contents)->toBe($pdf)
        ->and($writer->submissions[0]->nativeGoogleFormat)->toBeFalse()
        ->and($writer->submissions[0]->filename)->toBe('invoice.pdf')
        ->and($handoff->requests[0]->mediaType)->toBe('application/pdf')
        ->and($result->formatHandoff['status'] ?? null)->toBe('deferred');
});

it('replays the same file id and revision without duplicating history', function (): void {
    [$fileId, $client, , $revision] = googleDriveDocFixture();
    [$launcher, $installation, $fixtureClient, $writer] = googleDriveLauncher($client);
    $authorization = googleDriveAuthorization('3');

    $first = $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/main',
        fileId: $fileId,
        authorization: $authorization,
    ));
    $duplicate = $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/main',
        fileId: $fileId,
        authorization: $authorization,
    ));

    expect($duplicate->replayed)->toBeTrue()
        ->and($duplicate->runId)->toBe($first->runId)
        ->and($duplicate->revisionId)->toBe($revision)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($fixtureClient->exportCalls)->toHaveCount(1);
});

it('treats missing export permission as an explicit failure, not an empty file', function (): void {
    $fileId = 'denied-doc';
    $client = new FixtureGoogleDriveFileClient(
        [
            $fileId => new GoogleDriveFileMetadata($fileId, 'rev-denied', GoogleDriveExportPlan::DOCS_MIME, 'Secret'),
        ],
        [
            $fileId => new GoogleDriveExportDenied('The user does not have permission to export this file.'),
        ],
    );
    [$launcher, $installation] = googleDriveLauncher($client);

    expect(fn () => $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/main',
        fileId: $fileId,
        authorization: googleDriveAuthorization('4'),
    )))->toThrow(GoogleDriveExportDenied::class, 'permission to export');
});

it('refuses cleanly when Drive secrets are missing', function (): void {
    $nullClient = new NullGoogleDriveFileClient;
    $registry = app(ConnectorRegistry::class);
    $connector = new GoogleDriveConnector($nullClient);
    $registry->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'Google Drive without secrets');
    $launcher = new LaunchGoogleDriveIngestion(
        new LaunchIngestion(
            $registry,
            app(ConnectorInstallations::class),
            app(IngestionRuns::class),
            new GoogleDriveNullManualDispatcher,
        ),
        app(IngestionRuns::class),
        $registry,
        $nullClient,
        new RecordingGoogleDriveWriter,
        new AbsentDocumentFormatHandoff,
    );

    expect(fn () => $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/main',
        fileId: 'any-file',
        authorization: googleDriveAuthorization('5'),
    )))->toThrow(MissingGoogleDriveCredentials::class, 'credentials are not configured')
        ->and(fn () => $connector->downloadArtifact(new ArtifactRequest(
            sourceReference: 'google-drive:workspace/main',
            artifactReference: 'google-drive://file/any-file/revision/x',
            parameters: ['file_id' => 'any-file'],
        )))->toThrow(MissingGoogleDriveCredentials::class);
});

it('implements a real DownloadsArtifacts downloadArtifact for Drive exports', function (): void {
    [$fileId, $client, $docx, $revision] = googleDriveDocFixture();
    $connector = new GoogleDriveConnector($client);

    $artifact = $connector->downloadArtifact(new ArtifactRequest(
        sourceReference: 'google-drive:workspace/main',
        artifactReference: GoogleDriveConnector::artifactReference($fileId, $revision),
        parameters: ['file_id' => $fileId],
    ));

    expect($artifact->contents)->toBe($docx)
        ->and($artifact->mediaType)->toBe(GoogleDriveExportPlan::DOCX)
        ->and($artifact->metadata['file_id'] ?? null)->toBe($fileId)
        ->and($artifact->metadata['revision_id'] ?? null)->toBe($revision)
        ->and($connector)->toBeInstanceOf(DownloadsArtifacts::class);
});

it('treats empty Drive export bytes as an explicit export denial', function (): void {
    $fileId = 'empty-doc';
    $client = new FixtureGoogleDriveFileClient(
        [
            $fileId => new GoogleDriveFileMetadata($fileId, 'rev-empty', GoogleDriveExportPlan::DOCS_MIME, 'Empty'),
        ],
        [
            $fileId => new GoogleDriveExportResult(
                $fileId,
                'rev-empty',
                GoogleDriveExportPlan::DOCS_MIME,
                GoogleDriveExportPlan::DOCX,
                'docx',
                'Empty.docx',
                '',
                true,
            ),
        ],
    );
    [$launcher, $installation] = googleDriveLauncher($client);

    expect(fn () => $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/main',
        fileId: $fileId,
        authorization: googleDriveAuthorization('7'),
    )))->toThrow(GoogleDriveExportDenied::class, 'empty interchange bytes');
});

it('exports docs to markdown when preferred and hands the text interchange to the formatter', function (): void {
    $fileId = 'doc-md-1';
    $revision = 'rev-md-1';
    $markdown = "# Notes\n\nBody";
    $client = new FixtureGoogleDriveFileClient(
        [
            $fileId => new GoogleDriveFileMetadata($fileId, $revision, GoogleDriveExportPlan::DOCS_MIME, 'Notes'),
        ],
        [
            $fileId => new GoogleDriveExportResult(
                $fileId,
                $revision,
                GoogleDriveExportPlan::DOCS_MIME,
                GoogleDriveExportPlan::MARKDOWN,
                'md',
                'Notes.md',
                $markdown,
                true,
            ),
        ],
    );
    [$launcher, $installation, , $writer, $handoff] = googleDriveLauncher($client);

    $result = $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/main',
        fileId: $fileId,
        authorization: googleDriveAuthorization('6'),
        preferredExtension: 'md',
    ));

    expect($result->exportExtension)->toBe('md')
        ->and($result->exportMediaType)->toBe(GoogleDriveExportPlan::MARKDOWN)
        ->and($writer->submissions[0]->contents)->toBe($markdown)
        ->and($handoff->requests[0]->mediaType)->toBe(GoogleDriveExportPlan::MARKDOWN);
});

it('records DOCX text as one versioned Funes extraction', function (): void {
    $fileId = 'formatted-docx';
    $revision = 'formatted-docx-rev';
    $docx = googleDriveDocx('Quarterly notes', 'Revenue is up.');
    $client = new FixtureGoogleDriveFileClient(
        [$fileId => new GoogleDriveFileMetadata($fileId, $revision, GoogleDriveExportPlan::DOCS_MIME, 'Quarterly Notes')],
        [$fileId => new GoogleDriveExportResult(
            $fileId,
            $revision,
            GoogleDriveExportPlan::DOCS_MIME,
            GoogleDriveExportPlan::DOCX,
            'docx',
            'Quarterly Notes.docx',
            $docx,
            true,
        )],
    );
    [$launcher, $installation] = realGoogleDriveLauncher($client);

    $result = $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/formatter',
        fileId: $fileId,
        authorization: googleDriveAuthorization('formatted-docx'),
    ));
    $extraction = DB::table('funes_extractions')->first();
    $derived = json_decode((string) $extraction->result, true, 512, JSON_THROW_ON_ERROR);

    expect(app(DocumentFormatHandoff::class))->toBeInstanceOf(FunesDocumentFormatHandoff::class)
        ->and(DB::table('funes_observations')->count())->toBe(1)
        ->and(DB::table('funes_extractions')->count())->toBe(1)
        ->and($extraction->observation_id)->toBe($result->acceptedReferences[0])
        ->and($extraction->extractor)->toBe('aleph.document.local')
        ->and($extraction->version)->toBe('2')
        ->and($derived['kind'])->toBe('document_text')
        ->and($derived['text'])->toBe("Quarterly notes\n\nRevenue is up.")
        ->and($derived['source']['sha256'])->toBe(hash('sha256', $docx))
        ->and($result->formatHandoff['format_run_id'] ?? null)->toBe($extraction->id);

    $replayed = app(DocumentFormatHandoff::class)->handOff(new DocumentFormatHandoffRequest(
        sourceReference: 'google-drive:workspace/formatter',
        sourceInstallationId: $installation->id,
        driveRunId: $result->runId,
        acceptedObservationId: $result->acceptedReferences[0],
        artifactReference: GoogleDriveConnector::artifactReference($fileId, $revision),
        filename: 'Quarterly Notes.docx',
        mediaType: GoogleDriveExportPlan::DOCX,
        contents: $docx,
        checksum: hash('sha256', $docx),
        bytes: strlen($docx),
    ));

    expect($replayed->formatRunId)->toBe($extraction->id)
        ->and(DB::table('funes_extractions')->count())->toBe(1);
});

it('uses the same extraction contract for PDF without another observation', function (): void {
    $fileId = 'formatted-pdf';
    $revision = 'formatted-pdf-rev';
    $pdf = pdfWithEmbeddedText('Drive PDF body');
    $client = new FixtureGoogleDriveFileClient(
        [$fileId => new GoogleDriveFileMetadata($fileId, $revision, GoogleDriveExportPlan::PDF, 'notes.pdf')],
        [$fileId => new GoogleDriveExportResult(
            $fileId,
            $revision,
            GoogleDriveExportPlan::PDF,
            GoogleDriveExportPlan::PDF,
            'pdf',
            'notes.pdf',
            $pdf,
            false,
        )],
    );
    [$launcher, $installation] = realGoogleDriveLauncher($client);

    $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/pdf',
        fileId: $fileId,
        authorization: googleDriveAuthorization('formatted-pdf'),
    ));
    $extraction = DB::table('funes_extractions')->first();
    $derived = json_decode((string) $extraction->result, true, 512, JSON_THROW_ON_ERROR);

    expect(DB::table('funes_observations')->count())->toBe(1)
        ->and($derived['kind'])->toBe('document_text')
        ->and($derived['text'])->toContain('Drive PDF body')
        ->and($derived['source']['media_type'])->toBe(GoogleDriveExportPlan::PDF);
});

it('records PPTX slides in their numbered order', function (): void {
    $fileId = 'formatted-pptx';
    $revision = 'formatted-pptx-rev';
    $pptx = googleDriveOfficeArchive([
        'ppt/slides/slide10.xml' => '<?xml version="1.0"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><a:p><a:r><a:t>Tenth slide</a:t></a:r></a:p></p:cSld></p:sld>',
        'ppt/slides/slide2.xml' => '<?xml version="1.0"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><a:p><a:r><a:t>Second </a:t></a:r><a:r><a:t>slide</a:t></a:r></a:p></p:cSld></p:sld>',
        'ppt/slides/slide1.xml' => '<?xml version="1.0"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><a:p><a:r><a:t>First slide</a:t></a:r></a:p></p:cSld></p:sld>',
    ]);
    $client = new FixtureGoogleDriveFileClient(
        [$fileId => new GoogleDriveFileMetadata($fileId, $revision, GoogleDriveExportPlan::SLIDES_MIME, 'Deck')],
        [$fileId => new GoogleDriveExportResult(
            $fileId,
            $revision,
            GoogleDriveExportPlan::SLIDES_MIME,
            GoogleDriveExportPlan::PPTX,
            'pptx',
            'Deck.pptx',
            $pptx,
            true,
        )],
    );
    [$launcher, $installation] = realGoogleDriveLauncher($client);

    $result = $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/pptx',
        fileId: $fileId,
        authorization: googleDriveAuthorization('formatted-pptx'),
    ));
    $extraction = DB::table('funes_extractions')->first();
    $derived = json_decode((string) $extraction->result, true, 512, JSON_THROW_ON_ERROR);

    expect($result->formatHandoff['status'] ?? null)->toBe('launched')
        ->and($derived['text'])->toBe("First slide\n\nSecond slide\n\nTenth slide")
        ->and($derived['source']['media_type'])->toBe(GoogleDriveExportPlan::PPTX)
        ->and(DB::table('funes_observations')->count())->toBe(1)
        ->and(DB::table('funes_extractions')->count())->toBe(1);
});

it('records XLSX shared inline numeric and boolean cells', function (): void {
    $fileId = 'formatted-xlsx';
    $revision = 'formatted-xlsx-rev';
    $xlsx = googleDriveOfficeArchive([
        'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Name</t></si><si><r><t>Gross </t></r><r><t>sales</t></r></si></sst>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row><c t="s"><v>0</v></c><c t="s"><v>1</v></c><c t="inlineStr"><is><t>Approved</t></is></c></row><row><c t="inlineStr"><is><t>East</t></is></c><c><v>1250.50</v></c><c t="b"><v>1</v></c></row></sheetData></worksheet>',
        'xl/worksheets/sheet2.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row><c t="inlineStr"><is><t>Notes</t></is></c></row></sheetData></worksheet>',
    ]);
    $client = new FixtureGoogleDriveFileClient(
        [$fileId => new GoogleDriveFileMetadata($fileId, $revision, GoogleDriveExportPlan::SHEETS_MIME, 'Report')],
        [$fileId => new GoogleDriveExportResult(
            $fileId,
            $revision,
            GoogleDriveExportPlan::SHEETS_MIME,
            GoogleDriveExportPlan::XLSX,
            'xlsx',
            'Report.xlsx',
            $xlsx,
            true,
        )],
    );
    [$launcher, $installation] = realGoogleDriveLauncher($client);

    $result = $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/xlsx',
        fileId: $fileId,
        authorization: googleDriveAuthorization('formatted-xlsx'),
    ));
    $extraction = DB::table('funes_extractions')->first();
    $derived = json_decode((string) $extraction->result, true, 512, JSON_THROW_ON_ERROR);

    expect($result->formatHandoff['status'] ?? null)->toBe('launched')
        ->and($derived['text'])->toBe("Name\tGross sales\tApproved\nEast\t1250.50\tTRUE\n\nNotes")
        ->and($derived['source']['media_type'])->toBe(GoogleDriveExportPlan::XLSX);
});

it('leaves malformed supported documents retryable without a derivation', function (): void {
    $fileId = 'malformed-docx';
    $revision = 'malformed-docx-rev';
    $client = new FixtureGoogleDriveFileClient(
        [$fileId => new GoogleDriveFileMetadata($fileId, $revision, GoogleDriveExportPlan::DOCS_MIME, 'Broken')],
        [$fileId => new GoogleDriveExportResult(
            $fileId,
            $revision,
            GoogleDriveExportPlan::DOCS_MIME,
            GoogleDriveExportPlan::DOCX,
            'docx',
            'Broken.docx',
            'not a zip archive',
            true,
        )],
    );
    [$launcher, $installation] = realGoogleDriveLauncher($client);

    expect(fn () => $launcher->launch(new LaunchGoogleDriveIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'google-drive:workspace/broken',
        fileId: $fileId,
        authorization: googleDriveAuthorization('malformed-docx'),
    )))->toThrow(RuntimeException::class, 'DOCX archive could not be opened');

    $attempt = DB::table('aleph_ingestion_attempts')->first();
    $failure = json_decode((string) $attempt->failure, true, 512, JSON_THROW_ON_ERROR);

    expect($attempt->status)->toBe('failed')
        ->and($failure['retryable'])->toBeTrue()
        ->and(DB::table('funes_extractions')->count())->toBe(0);
});
