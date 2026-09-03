<?php

declare(strict_types=1);

use RuntimeException;
use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\NativePhpDesktop\LaunchNativePhpDesktopFreeformIngestion;
use Sifrious\Aleph\Connector\NativePhpDesktop\LaunchNativePhpDesktopFreeformIngestionRequest;
use Sifrious\Aleph\Connector\NativePhpDesktop\NativePhpDesktopConnector;
use Sifrious\Aleph\Connector\NativePhpDesktop\NativePhpDesktopFreeformSubmission;
use Sifrious\Aleph\Connector\NativePhpDesktop\NativePhpDesktopObservationWriter;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\LaunchAuthorization;
use Sifrious\Aleph\Ingestion\LaunchIngestion;
use Sifrious\Aleph\Ingestion\LaunchIngestionResult;
use Sifrious\Aleph\Ingestion\ManualIngestionDispatcher;
use Sifrious\Aleph\Ingestion\RunStatus;

final class NullNativePhpDesktopManualDispatcher implements ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void {}
}

final class RecordingNativePhpDesktopWriter implements NativePhpDesktopObservationWriter
{
    /** @var list<NativePhpDesktopFreeformSubmission> */
    public array $submissions = [];

    public int $failuresRemaining = 0;

    public function write(NativePhpDesktopFreeformSubmission $submission, string $attemptId): string
    {
        $this->submissions[] = $submission;

        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new RuntimeException('transient_nativephp_desktop_write_failure');
        }

        return 'accepted:'.$submission->artifactReference;
    }
}

function nativePhpDesktopLauncher(): array
{
    $registry = app(ConnectorRegistry::class);
    $connector = new NativePhpDesktopConnector;
    $registry->register($connector);
    $installations = app(ConnectorInstallations::class);
    $firstInstallation = $installations->create(
        $connector,
        'NativePHP Desktop',
        owner: 'identity:user/mary',
    );
    $secondInstallation = $installations->create(
        $connector,
        'NativePHP Desktop Backup',
        owner: 'identity:user/mary',
    );
    $launch = new LaunchIngestion(
        $registry,
        $installations,
        app(IngestionRuns::class),
        new NullNativePhpDesktopManualDispatcher,
    );
    $writer = new RecordingNativePhpDesktopWriter;

    return [
        new LaunchNativePhpDesktopFreeformIngestion($launch, app(IngestionRuns::class), $registry, $writer),
        $firstInstallation,
        $secondInstallation,
        $writer,
    ];
}

it('launches one nativephp desktop freeform ingestion and preserves raw text', function (): void {
    [$launcher, $installation, , $writer] = nativePhpDesktopLauncher();
    $body = "Line one\n\nLine two with trailing spaces   ";
    $request = new LaunchNativePhpDesktopFreeformIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'nativephp-desktop://device/workstation-1',
        body: $body,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:nativephp-desktop/1'),
    );

    $result = $launcher->launch($request);
    $run = app(IngestionRuns::class)->find($result->runId);
    $submitted = $writer->submissions[0] ?? null;
    $expectedHash = hash('sha256', $body);
    $expectedArtifactReference = sprintf('nativephp-desktop://%s/freeform/%s', $installation->id, $expectedHash);

    expect($result->replayed)->toBeFalse()
        ->and($result->artifactReference)->toBe($expectedArtifactReference)
        ->and($run)->not->toBeNull()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and($run?->connectorId)->toBe('nativephp-desktop')
        ->and($run?->parameters['source_identity'] ?? null)->toBe('nativephp-desktop')
        ->and($run?->parameters['body'] ?? null)->toBe($body)
        ->and($submitted)->not->toBeNull()
        ->and($submitted?->body)->toBe($body)
        ->and($submitted?->sha256)->toBe($expectedHash)
        ->and($submitted?->artifactReference)->toBe($expectedArtifactReference)
        ->and($writer->submissions)->toHaveCount(1);
});

it('replays the same freeform body for the same installation', function (): void {
    [$launcher, $installation, , $writer] = nativePhpDesktopLauncher();
    $body = "Repeated freeform capture\nwith the same payload";
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:nativephp-desktop/2');

    $first = $launcher->launch(new LaunchNativePhpDesktopFreeformIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'nativephp-desktop://device/workstation-1',
        body: $body,
        authorization: $authorization,
    ));
    $duplicate = $launcher->launch(new LaunchNativePhpDesktopFreeformIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'nativephp-desktop://device/workstation-1',
        body: $body,
        authorization: $authorization,
    ));

    expect($duplicate->replayed)->toBeTrue()
        ->and($duplicate->runId)->toBe($first->runId)
        ->and($duplicate->artifactReference)->toBe($first->artifactReference)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and($writer->submissions)->toHaveCount(1);
});

it('uses installation plus content hash for idempotency', function (): void {
    [$launcher, $installationA, $installationB, $writer] = nativePhpDesktopLauncher();
    $body = "Same text, different installation should not replay.";
    $authorization = LaunchAuthorization::granted('identity:user/mary', 'authorization:nativephp-desktop/3');

    $first = $launcher->launch(new LaunchNativePhpDesktopFreeformIngestionRequest(
        sourceInstallationId: $installationA->id,
        sourceReference: 'nativephp-desktop://device/workstation-1',
        body: $body,
        authorization: $authorization,
    ));
    $second = $launcher->launch(new LaunchNativePhpDesktopFreeformIngestionRequest(
        sourceInstallationId: $installationB->id,
        sourceReference: 'nativephp-desktop://device/workstation-2',
        body: $body,
        authorization: $authorization,
    ));

    expect($first->runId)->not->toBe($second->runId)
        ->and($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeFalse()
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(2)
        ->and($writer->submissions)->toHaveCount(2);
});

it('retries a replayed retryable failed run instead of returning immediately', function (): void {
    [$launcher, $installation, , $writer] = nativePhpDesktopLauncher();
    $body = "Transient failure should be retryable on replay.";
    $request = new LaunchNativePhpDesktopFreeformIngestionRequest(
        sourceInstallationId: $installation->id,
        sourceReference: 'nativephp-desktop://device/workstation-1',
        body: $body,
        authorization: LaunchAuthorization::granted('identity:user/mary', 'authorization:nativephp-desktop/4'),
    );
    $writer->failuresRemaining = 1;

    expect(fn () => $launcher->launch($request))
        ->toThrow(RuntimeException::class, 'transient_nativephp_desktop_write_failure');

    $result = $launcher->launch($request);
    $run = app(IngestionRuns::class)->find($result->runId);

    expect($result->replayed)->toBeFalse()
        ->and($run)->not->toBeNull()
        ->and($run?->status)->toBe(RunStatus::Completed)
        ->and(DB::table('aleph_ingestion_runs')->count())->toBe(1)
        ->and(DB::table('aleph_ingestion_attempts')->count())->toBe(2)
        ->and($writer->submissions)->toHaveCount(2);
});
