<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Midi\LaunchMidiIngestion;
use Sifrious\Aleph\Connector\Midi\LaunchMidiIngestionRequest;
use Sifrious\Aleph\Connector\Midi\LocalMidiFilePayload;
use Sifrious\Aleph\Connector\Midi\MidiArtifactSubmission;
use Sifrious\Aleph\Connector\Midi\MidiConnector;
use Sifrious\Aleph\Connector\Midi\MidiExtractionRecorder;
use Sifrious\Aleph\Connector\Midi\MidiObservationWriter;
use Sifrious\Aleph\Connector\Midi\MidiParseResult;
use Sifrious\Aleph\Connector\Midi\OursSmfMidiParser;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;
use Sifrious\Aleph\Tests\Fixtures\MidiFixture;

final class MidiNullManualDispatcher implements ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void {}
}

final class RecordingMidiWriter implements MidiObservationWriter
{
    /** @var list<MidiArtifactSubmission> */
    public array $submissions = [];

    public function write(MidiArtifactSubmission $submission, string $attemptId): string
    {
        $this->submissions[] = $submission;

        return 'accepted:'.$submission->artifactReference;
    }
}

final class RecordingMidiExtractionRecorder implements MidiExtractionRecorder
{
    /** @var list<array{observation_id: string, parse: MidiParseResult, checksum: string, bytes: int, run_id: string}> */
    public array $records = [];

    public function record(
        string $observationId,
        MidiParseResult $parse,
        string $checksum,
        int $bytes,
        string $runId,
    ): void {
        $this->records[] = [
            'observation_id' => $observationId,
            'parse' => $parse,
            'checksum' => $checksum,
            'bytes' => $bytes,
            'run_id' => $runId,
        ];
    }
}

/**
 * @return array{0: LaunchMidiIngestion, 1: mixed, 2: mixed, 3: RecordingMidiWriter, 4: RecordingMidiExtractionRecorder}
 */
function midiLauncher(): array
{
    $registry = app(ConnectorRegistry::class);
    $connector = new MidiConnector;
    $registry->register($connector);
    $installations = app(ConnectorInstallations::class);
    $firstInstallation = $installations->create(
        $connector,
        'MIDI files',
        owner: 'identity:user/mary',
    );
    $secondInstallation = $installations->create(
        $connector,
        'MIDI files backup',
        owner: 'identity:user/mary',
    );
    $launch = new LaunchIngestion(
        $registry,
        $installations,
        app(IngestionRuns::class),
        new MidiNullManualDispatcher,
    );
    $writer = new RecordingMidiWriter;
    $extractions = new RecordingMidiExtractionRecorder;

    return [
        new LaunchMidiIngestion(
            $launch,
            app(IngestionRuns::class),
            $registry,
            $writer,
            new OursSmfMidiParser,
            $extractions,
        ),
        $firstInstallation,
        $secondInstallation,
        $writer,
        $extractions,
    ];
}

function tempMidi(string $contents, string $extension = 'mid'): string
{
    $path = sys_get_temp_dir().'/aleph-midi-'.bin2hex(random_bytes(6)).'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

it('ingests one local MIDI path through LaunchIngestion and records SMF events', function (): void {
    [$launcher, $installation, , $writer, $extractions] = midiLauncher();
    $bytes = MidiFixture::smf0Simple();
    $path = tempMidi($bytes);
    $request = LaunchMidiIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:midi-library',
        path: $path,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:midi/1'),
    );

    $result = $launcher->launch($request);
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;
    $recorded = $extractions->records[0] ?? null;

    expect($result->replayed)->toBeFalse()
        ->and($run)->not->toBeNull()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->acceptedReferences)->toBe($result->acceptedReferences)
        ->and($run?->connectorId)->toBe('midi')
        ->and($run?->parameters['sha256'] ?? null)->toBe(hash('sha256', $bytes))
        ->and($run?->parameters['parser'] ?? null)->toBe('ours')
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted?->artifactReference)->toBe('file://'.realpath($path))
        ->and($submitted?->mediaType)->toBe('audio/midi')
        ->and($submitted?->bytes)->toBe(strlen($bytes))
        ->and($submitted?->checksum)->toBe(hash('sha256', $bytes))
        ->and($submitted?->parse->format)->toBe(0)
        ->and($submitted?->metadata['parser'] ?? null)->toBe('ours')
        ->and($submitted?->metadata['smf_format'] ?? null)->toBe(0)
        ->and($extractions->records)->toHaveCount(1)
        ->and($recorded['parse']->format)->toBe(0)
        ->and(collect($recorded['parse']->events)->pluck('type')->all())->toBe([
            'time_signature',
            'tempo',
            'note_on',
            'note_off',
        ])
        ->and($recorded['parse']->toExtractionResult($recorded['checksum'], $recorded['bytes'])['parser'])
        ->toMatchArray(['name' => 'ours', 'version' => '1.0.0']);

    @unlink($path);
});

it('ingests direct MIDI file payload bytes for SMF 1', function (): void {
    [$launcher, $installation, , $writer, $extractions] = midiLauncher();
    $bytes = MidiFixture::smf1TwoTracks();
    $payload = new LocalMidiFilePayload('song.midi', $bytes, 'audio/midi');
    $request = LaunchMidiIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:midi-library',
        file: $payload,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:midi/2'),
    );

    $result = $launcher->launch($request);
    $submitted = $writer->submissions[0] ?? null;
    $expectedReference = 'memory://song.midi#sha256:'.hash('sha256', $bytes);

    expect($result->replayed)->toBeFalse()
        ->and($writer->submissions)->toHaveCount(1)
        ->and($submitted?->artifactReference)->toBe($expectedReference)
        ->and($submitted?->parse->format)->toBe(1)
        ->and($submitted?->parse->trackCount)->toBe(2)
        ->and($extractions->records)->toHaveCount(1)
        ->and($extractions->records[0]['parse']->format)->toBe(1);
});

it('replays the same MIDI bytes on duplicate submit', function (): void {
    [$launcher, $installation, , $writer, $extractions] = midiLauncher();
    $bytes = MidiFixture::smf0Simple();
    $firstPath = tempMidi($bytes);
    $duplicatePath = tempMidi($bytes);
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:midi/3');

    $first = $launcher->launch(LaunchMidiIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:midi-library',
        path: $firstPath,
        authorization: $authorization,
    ));
    $duplicate = $launcher->launch(LaunchMidiIngestionRequest::fromPath(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:midi-library',
        path: $duplicatePath,
        authorization: $authorization,
    ));

    expect($duplicate->replayed)->toBeTrue()
        ->and($duplicate->runId)->toBe($first->runId)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and($writer->submissions)->toHaveCount(1)
        ->and($extractions->records)->toHaveCount(1);

    @unlink($firstPath);
    @unlink($duplicatePath);
});

it('uses installation plus content hash for MIDI idempotency', function (): void {
    [$launcher, $installationA, $installationB, $writer, $extractions] = midiLauncher();
    $payload = new LocalMidiFilePayload('shared.mid', MidiFixture::smf0Simple(), 'audio/midi');
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:midi/4');

    $first = $launcher->launch(LaunchMidiIngestionRequest::fromFile(
        sourceInstallationId: $installationA->id,
        sourceReference: 'desktop:midi-library:a',
        file: $payload,
        authorization: $authorization,
    ));
    $second = $launcher->launch(LaunchMidiIngestionRequest::fromFile(
        sourceInstallationId: $installationB->id,
        sourceReference: 'desktop:midi-library:b',
        file: $payload,
        authorization: $authorization,
    ));

    expect($first->runId)->not->toBe($second->runId)
        ->and($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeFalse()
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(2)
        ->and($writer->submissions)->toHaveCount(2)
        ->and($extractions->records)->toHaveCount(2);
});

it('names the in-house parser as ours on the submission', function (): void {
    [$launcher, $installation, , $writer] = midiLauncher();
    $payload = new LocalMidiFilePayload('named.mid', MidiFixture::smf0Simple());

    $launcher->launch(LaunchMidiIngestionRequest::fromFile(
        sourceInstallationId: $installation->id,
        sourceReference: 'desktop:midi-library',
        file: $payload,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:midi/5'),
    ));

    expect($writer->submissions[0]->metadata['parser'] ?? null)->toBe(MidiParseResult::PARSER_NAME)
        ->and(MidiParseResult::PARSER_NAME)->toBe('ours');
});

it('implements a real DownloadsArtifacts downloadArtifact for path and file inputs', function (): void {
    $connector = new MidiConnector;
    $bytes = MidiFixture::smf0Simple();
    $path = tempMidi($bytes, 'midi');

    $fromPath = $connector->downloadArtifact(new \Sifrious\Aleph\Connector\Values\ArtifactRequest(
        sourceReference: 'desktop:midi-library',
        artifactReference: 'file://'.$path,
        parameters: ['input' => 'path', 'path' => $path],
    ));
    $fromFile = $connector->downloadArtifact(new \Sifrious\Aleph\Connector\Values\ArtifactRequest(
        sourceReference: 'desktop:midi-library',
        artifactReference: 'memory://probe.mid',
        parameters: [
            'input' => 'file',
            'name' => 'probe.mid',
            'contents_base64' => base64_encode($bytes),
            'media_type' => 'audio/midi',
        ],
    ));

    expect($connector)->toBeInstanceOf(\Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts::class)
        ->and($fromPath->contents)->toBe($bytes)
        ->and($fromPath->mediaType)->toBe('audio/midi')
        ->and($fromFile->contents)->toBe($bytes)
        ->and($fromFile->mediaType)->toBe('audio/midi');

    @unlink($path);
});
