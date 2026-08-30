<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\NativePhpDesktop;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionRequest;
use Sifrious\Aleph\Ingestion\RunFailure;
use Throwable;

final readonly class LaunchNativePhpDesktopFreeformIngestion
{
    public function __construct(
        private LaunchIngestion $launcher,
        private IngestionRuns $runs,
        private NativePhpDesktopObservationWriter $writer,
    ) {}

    public function launch(LaunchNativePhpDesktopFreeformIngestionRequest $request): LaunchNativePhpDesktopFreeformIngestionResult
    {
        if (trim($request->body) === '') {
            throw new InvalidArgumentException('A freeform capture body is required.');
        }

        $sha256 = hash('sha256', $request->body);
        $bytes = strlen($request->body);
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
                'bytes' => $bytes,
            ],
            idempotencyKey: $idempotency,
            authorization: $request->authorization,
        ));
        $run = $launch->run;

        if ($launch->replayed) {
            return new LaunchNativePhpDesktopFreeformIngestionResult(
                $run->id,
                true,
                $artifactReference,
                $run->acceptedReferences,
            );
        }

        $existing = $this->runs->find($run->id);

        if ($existing !== null && $existing->acceptedReferences !== []) {
            return new LaunchNativePhpDesktopFreeformIngestionResult(
                $existing->id,
                false,
                $artifactReference,
                $existing->acceptedReferences,
            );
        }

        if ($existing !== null && $this->runs->activeAttempt($existing) !== null) {
            return new LaunchNativePhpDesktopFreeformIngestionResult(
                $existing->id,
                false,
                $artifactReference,
                $existing->acceptedReferences,
            );
        }

        $attempt = $this->runs->beginAttempt($run);

        try {
            $accepted = $this->writer->write(new NativePhpDesktopFreeformSubmission(
                sourceReference: $request->sourceReference,
                sourceInstallationId: $request->sourceInstallationId,
                runId: $run->id,
                artifactReference: $artifactReference,
                body: $request->body,
                sha256: $sha256,
                bytes: $bytes,
            ), $attempt->id);

            $this->runs->succeedAttempt(
                $run,
                $attempt,
                ['artifacts' => 1, 'accepted' => 1, 'bytes' => $bytes],
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
}
