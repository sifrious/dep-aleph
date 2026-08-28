<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class IngestShellHistory
{
    public function __construct(
        private ConnectorInstallations $installations,
        private EnvelopeSubmitter $submitter,
        private ShellRedactionPolicy $redaction,
        private ShellCommandTokenizer $tokenizer,
    ) {}

    /**
     * @param  list<ShellCommandObservation>  $commands
     */
    public function ingest(
        string $sourceReference,
        string $sourceInstallationId,
        array $commands,
        DateTimeImmutable $capturedAt,
        ?string $attemptId = null,
    ): ShellIngestionResult {
        $installation = $this->installations->find($sourceInstallationId);

        if ($installation === null) {
            throw new InvalidArgumentException('Shell ingestion requires an existing source installation.');
        }

        $accepted = [];
        $redactedCount = 0;

        foreach ($commands as $command) {
            $redacted = $this->redaction->apply($command);
            $redactedCount += $redacted->decision === RedactionDecision::Redacted ? 1 : 0;
            $payload = [
                'actor' => $command->actor->value,
                'argv' => $this->tokenizer->tokenize($redacted->command),
                'binary' => $this->tokenizer->tokenize($redacted->command)[0] ?? null,
                'command' => $redacted->command,
                'command_hash' => $redacted->originalCommandHash,
                'context' => [
                    'cwd' => $command->context->cwd,
                    'environment_reference' => $command->context->environmentReference,
                    'host' => $command->context->host,
                    'session' => $command->context->session,
                    'shell' => $command->context->shell,
                    'user' => $command->context->user,
                ],
                'duration_ms' => $command->durationMilliseconds,
                'exit_code' => $command->exitCode,
                'output' => $redacted->output,
                'provider' => $command->provider->value,
                'redaction' => [
                    'decision' => $redacted->decision->value,
                    'policy' => 'shell.secrets:1',
                    'reasons' => $redacted->reasons,
                ],
                'source_record_id' => $command->sourceRecordId,
                'source_revision' => $command->sourceRevision,
            ];
            $outcome = $this->submitter->submit(new ObservationEnvelope(
                sourceReference: $sourceReference,
                sourceName: $command->context->host.':'.$command->context->user,
                resourceReference: implode('/', [
                    $sourceReference,
                    $command->provider->value,
                    hash('sha256', $command->context->host.'|'.$command->context->user.'|'.$command->sourceRecordId),
                ]),
                observedAt: $capturedAt,
                payload: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                provenance: new Provenance('shell-history', '1.0.0', $installation->id, $capturedAt, details: [
                    'raw_reference' => $sourceReference.'/'.$command->sourceRevision.'/'.$command->sourceRecordId,
                ]),
                contentType: 'application/json',
                account: $installation->externalAccountId,
                stream: $command->context->host.'/'.$command->context->user.'/'.$command->context->shell,
                eventType: 'shell.command',
                providerId: $command->sourceRecordId,
                providerRevision: $command->sourceRevision,
                extensions: [new ExtensionMetadata('shell.command', 1, [
                    'provider' => $command->provider->value,
                    'redaction' => $redacted->decision->value,
                ])],
                occurredAt: $command->executedAt,
            ), $attemptId);
            $acceptedId = $outcome->acceptedId();

            if (! $outcome->isAuthoritative() || $acceptedId === null) {
                throw new InvalidArgumentException('Funes did not accept the shell command.');
            }

            $accepted[] = $acceptedId;
        }

        return new ShellIngestionResult(count($commands), $redactedCount, array_values(array_unique($accepted)));
    }
}
