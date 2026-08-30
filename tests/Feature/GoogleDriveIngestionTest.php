<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\GoogleDrive\AbsentDocumentFormatHandoff;
use Sifrious\Aleph\Connector\GoogleDrive\DocumentFormatHandoff;
use Sifrious\Aleph\Connector\GoogleDrive\DocumentFormatHandoffRequest;
use Sifrious\Aleph\Connector\GoogleDrive\DocumentFormatHandoffResult;
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

it('ingests one Google Doc into interchange bytes plus a 777 format handoff', function (): void {
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
    $pdf = "%PDF-1.4 drive-fixture-bytes";
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
        ->and($connector)->toBeInstanceOf(\Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts::class);
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
