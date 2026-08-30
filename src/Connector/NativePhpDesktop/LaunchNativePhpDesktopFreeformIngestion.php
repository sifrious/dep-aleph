<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\NativePhpDesktop;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;
use Sifrious\Aleph\Ingestion\IngestionRun;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionRequest;
use Sifrious\Aleph\Ingestion\RunFailure;
use Sifrious\Aleph\Ingestion\RunStatus;
use Throwable;

final readonly class LaunchNativePhpDesktopFreeformIngestion
{
    public function __construct(
        private LaunchIngestion $launcher,
        private IngestionRuns $runs,
        private ConnectorRegistry $connectors,
        private NativePhpDesktopObservationWriter $writer,
    ) {}

    public function launch(LaunchNativePhpDesktopFreeformIngestionRequest $request): LaunchNativePhpDesktopFreeformIngestionResult
    {
        if (trim($request->body) === '') {
            throw new InvalidArgumentException('A freeform capture body is required.');
        }

        $sha256 = hash('sha256', $request->body);
        $artifactReference = sprintf('nativephp-desktop://%s/freeform/%s', $request->sourceInstallationId, $sha256);
        $idempotency = sprintf('nativephp-desktop:%s:%s', $request->sourceInstallationId, $sha256);
        $launch = $this->launcher->launch(new LaunchIngestionRequest(
            sourceInstallationId: $request->sourceInstallationId,
            sourceReference: $request->sourceReference,
            capability: Capability::DownloadsArtifacts,
            parameters: [
                'source_identity' => 'nativephp-desktop',
                'artifact_reference' => $artifactReference,
                'body' => $request->body,
                'sha256' => $sha256,
                'bytes' => strlen($request->body),
            ],
            idempotencyKey: $idempotency,
            authorization: $request->authorization,
        ));
        $run = $launch->run;

        if ($launch->replayed && ! $this->isRetryableFailedRun($run)) {
            return new LaunchNativePhpDesktopFreeformIngestionResult(
                $run->id,
                true,
                $artifactReference,
                $run->acceptedReferences,
            );
        }

        $existing = $this->runs->find($run->id) ?? $run;
        $retryableFailure = $this->isRetryableFailedRun($existing);

        if (! $retryableFailure && $existing->acceptedReferences !== []) {
            return new LaunchNativePhpDesktopFreeformIngestionResult(
                $existing->id,
                false,
                $artifactReference,
                $existing->acceptedReferences,
            );
        }

        if (! $retryableFailure && $this->runs->activeAttempt($existing) !== null) {
            return new LaunchNativePhpDesktopFreeformIngestionResult(
                $existing->id,
                false,
                $artifactReference,
                $existing->acceptedReferences,
            );
        }

        $attempt = $this->runs->beginAttempt($run);

        try {
            $connector = $this->connectors->get($run->connectorId ?? '');

            if (! $connector instanceof DownloadsArtifacts) {
                throw new InvalidArgumentException('The run connector does not support artifact downloads.');
            }

            $artifact = $connector->downloadArtifact(new ArtifactRequest(
                sourceReference: $request->sourceReference,
                artifactReference: $artifactReference,
                parameters: [
                    'body' => $request->body,
                    'sha256' => $sha256,
                ],
            ));

            $accepted = $this->writer->write(new NativePhpDesktopFreeformSubmission(
                sourceReference: $request->sourceReference,
                sourceInstallationId: $request->sourceInstallationId,
                runId: $run->id,
                artifactReference: $artifactReference,
                body: $artifact->contents,
                sha256: hash('sha256', $artifact->contents),
                bytes: $artifact->bytes(),
            ), $attempt->id);

            $this->runs->succeedAttempt(
                $run,
                $attempt,
                ['artifacts' => 1, 'accepted' => 1, 'bytes' => $artifact->bytes()],
                [$accepted],
            );
        } catch (Throwable $failure) {
            $this->runs->failAttempt(
                $run,
                $attempt,
                new RunFailure('nativephp_desktop_freeform_ingestion', $failure->getMessage(), true, ['failure' => $failure::class]),
            );

            throw $failure;
        }

        $fresh = $this->runs->find($run->id) ?? $run;

        return new LaunchNativePhpDesktopFreeformIngestionResult(
            $fresh->id,
            false,
            $artifactReference,
            $fresh->acceptedReferences,
        );
    }

    private function isRetryableFailedRun(IngestionRun $run): bool
    {
        return $run->status === RunStatus::Failed && $run->failure?->retryable === true;
    }
}
