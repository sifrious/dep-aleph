<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Midi;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionRequest;
use Sifrious\Aleph\Ingestion\RunFailure;
use Throwable;

final readonly class LaunchMidiIngestion
{
    public function __construct(
        private LaunchIngestion $launcher,
        private IngestionRuns $runs,
        private ConnectorRegistry $connectors,
        private MidiObservationWriter $writer,
        private MidiParser $parser,
        private MidiExtractionRecorder $extractions,
    ) {}

    public function launch(LaunchMidiIngestionRequest $request): LaunchMidiIngestionResult
    {
        $prepared = $this->prepare($request);
        $launch = $this->launcher->launch(new LaunchIngestionRequest(
            sourceInstallationId: $request->sourceInstallationId,
            sourceReference: $request->sourceReference,
            capability: Capability::DownloadsArtifacts,
            parameters: $prepared['run_parameters'],
            idempotencyKey: 'sha256:'.$prepared['checksum'],
            authorization: $request->authorization,
        ));
        $run = $launch->run;

        if ($launch->replayed) {
            return new LaunchMidiIngestionResult($run->id, true, $run->acceptedReferences);
        }

        $existing = $this->runs->find($run->id);

        if ($existing !== null && $existing->acceptedReferences !== []) {
            return new LaunchMidiIngestionResult($existing->id, false, $existing->acceptedReferences);
        }

        if ($existing !== null && $this->runs->activeAttempt($existing) !== null) {
            return new LaunchMidiIngestionResult($existing->id, false, $existing->acceptedReferences);
        }

        $attempt = $this->runs->beginAttempt($run);
        try {
            $connector = $this->connectors->get($run->connectorId ?? '');

            if (! $connector instanceof DownloadsArtifacts) {
                throw new InvalidArgumentException('The run connector does not support artifact downloads.');
            }

            $artifact = $connector->downloadArtifact(new ArtifactRequest(
                sourceReference: $request->sourceReference,
                artifactReference: $prepared['artifact_reference'],
                parameters: $prepared['connector_parameters'],
            ));
            $checksum = hash('sha256', $artifact->contents);

            if ($checksum !== $prepared['checksum']) {
                throw new InvalidArgumentException('MIDI content hash changed between launch and artifact download.');
            }

            $parse = $this->parser->parse($artifact->contents);
            $accepted = $this->writer->write(new MidiArtifactSubmission(
                sourceReference: $request->sourceReference,
                sourceInstallationId: $request->sourceInstallationId,
                runId: $run->id,
                artifactReference: $prepared['artifact_reference'],
                mediaType: $artifact->mediaType,
                contents: $artifact->contents,
                checksum: $checksum,
                bytes: strlen($artifact->contents),
                metadata: array_merge(
                    is_array($artifact->metadata) ? $artifact->metadata : [],
                    [
                        'smf_format' => $parse->format,
                        'parser' => MidiParseResult::PARSER_NAME,
                        'track_count' => $parse->trackCount,
                        'division' => $parse->division,
                    ],
                ),
                parse: $parse,
            ), $attempt->id);

            $this->extractions->record(
                $accepted,
                $parse,
                $checksum,
                strlen($artifact->contents),
                $run->id,
            );

            $this->runs->succeedAttempt(
                $run,
                $attempt,
                [
                    'artifacts' => 1,
                    'accepted' => 1,
                    'bytes' => strlen($artifact->contents),
                    'smf_format' => $parse->format,
                    'events' => count($parse->events),
                    'parser' => MidiParseResult::PARSER_NAME,
                ],
                [$accepted],
            );
        } catch (Throwable $failure) {
            $this->runs->failAttempt(
                $run,
                $attempt,
                new RunFailure('midi_ingestion', $failure->getMessage(), true, ['failure' => $failure::class]),
            );

            throw $failure;
        }

        $fresh = $this->runs->find($run->id) ?? $run;

        return new LaunchMidiIngestionResult($fresh->id, false, $fresh->acceptedReferences);
    }

    /**
     * @return array{
     *     artifact_reference: string,
     *     checksum: string,
     *     run_parameters: array<string, mixed>,
     *     connector_parameters: array<string, mixed>
     * }
     */
    private function prepare(LaunchMidiIngestionRequest $request): array
    {
        if ($request->path !== null && $request->file !== null) {
            throw new InvalidArgumentException('MIDI ingestion accepts either a path or a file payload, not both.');
        }

        if ($request->path !== null) {
            $raw = trim($request->path);
            $path = $raw === '' ? false : realpath($raw);

            if ($path === false || ! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException('MIDI path input must point to a readable file.');
            }

            $checksum = hash_file('sha256', $path);

            if (! is_string($checksum)) {
                throw new InvalidArgumentException('MIDI ingestion could not hash the supplied path input.');
            }

            return [
                'artifact_reference' => 'file://'.$path,
                'checksum' => $checksum,
                'run_parameters' => array_filter([
                    'input' => 'path',
                    'path' => $path,
                    'name' => basename($path),
                    'sha256' => $checksum,
                    'parser' => MidiParseResult::PARSER_NAME,
                ], static fn (mixed $value): bool => $value !== null),
                'connector_parameters' => array_filter([
                    'input' => 'path',
                    'path' => $path,
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }

        $file = $request->file;

        if ($file === null) {
            throw new InvalidArgumentException('MIDI ingestion requires either a local path or a file payload.');
        }

        if (trim($file->name) === '') {
            throw new InvalidArgumentException('MIDI file payload requires a stable file name.');
        }

        $checksum = hash('sha256', $file->contents);
        $artifactReference = 'memory://'.$file->name.'#sha256:'.$checksum;

        return [
            'artifact_reference' => $artifactReference,
            'checksum' => $checksum,
            'run_parameters' => array_filter([
                'input' => 'file',
                'name' => $file->name,
                'sha256' => $checksum,
                'media_type' => $file->mediaType,
                'parser' => MidiParseResult::PARSER_NAME,
            ], static fn (mixed $value): bool => $value !== null),
            'connector_parameters' => array_filter([
                'input' => 'file',
                'name' => $file->name,
                'contents_base64' => base64_encode($file->contents),
                'media_type' => $file->mediaType,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }
}
