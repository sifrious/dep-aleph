<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Sifrious\Aleph\Connector\Capability as ConnectorCapability;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Health\ConnectorHealthChecks;
use Sifrious\Aleph\Connector\Health\HealthCheck;
use Sifrious\Aleph\Connector\Health\HealthStatus;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\CheckpointRule;
use Sifrious\Aleph\Ingestion\CheckpointValue;
use Sifrious\Aleph\Ingestion\FreshnessExpectation;
use Sifrious\Aleph\Ingestion\IngestionCheckpoints;
use Sifrious\Aleph\Ingestion\IngestionRun;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\IngestionSchedules;
use Sifrious\Aleph\Ingestion\IngestionTrigger;
use Sifrious\Aleph\Ingestion\ScheduledIngestion;
use Sifrious\Aleph\Ingestion\ScheduledIngestionDispatcher;
use Sifrious\Aleph\Ingestion\SourceStreams;
use Sifrious\Aleph\Ingestion\SourceStreamStatuses;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

final class SourceLifecycleScheduleDispatcher implements ScheduledIngestionDispatcher
{
    public function __construct(private readonly IngestionRuns $runs) {}

    public function dispatch(ScheduledIngestion $ingestion): IngestionRun
    {
        $schedule = $ingestion->schedule;

        return $this->runs->request(
            sourceReference: 'scheduled:'.$schedule->sourceInstallationId,
            capability: Capability::from($schedule->capability->value),
            parameters: $schedule->constraints,
            connectorId: 'archive-drop',
            sourceInstallationId: $schedule->sourceInstallationId,
            idempotencyKey: 'schedule-command:'.$schedule->id.':'.$ingestion->dueAt->format(DATE_ATOM),
            trigger: IngestionTrigger::Scheduled,
            requestedBy: 'schedule:'.$schedule->id,
            authorizationDecision: 'schedule-enabled:'.$schedule->id,
        );
    }
}

function sourceLifecycleJson(string $command, array $parameters = []): array
{
    $exit = Artisan::call($command, [...$parameters, '--json' => true]);

    expect($exit)->toBe(0);

    return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
}

it('lists sources and shows their operational state as JSON', function (): void {
    $now = new DateTimeImmutable('2026-08-28T15:01:00+00:00');
    Carbon::setTestNow($now);
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installations = app(ConnectorInstallations::class);
    $installation = $installations->create($connector, 'Primary archive', owner: 'identity:user/mary');
    $installations->create($connector, 'Secondary archive');

    app(ConnectorHealthChecks::class)->record(
        $installation->id,
        HealthCheck::Authentication,
        HealthStatus::Healthy,
        'Authentication succeeded.',
        ['remaining' => 100],
        null,
        new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
        new DateTimeImmutable('2026-08-28T15:05:00+00:00'),
    );

    $stream = app(SourceStreams::class)->create($installation->id, 'collection:minutes', 'collection', 'minutes');
    $runs = app(IngestionRuns::class);
    $run = $runs->start(
        'archive-drop:minutes',
        Capability::IncrementalSync,
        [],
        connectorId: $connector->id(),
        sourceInstallationId: $installation->id,
    );
    $attempt = $runs->beginAttempt($run);
    $accepted = app(EnvelopeSubmitter::class)->submit(new ObservationEnvelope(
        sourceReference: $run->sourceReference,
        sourceName: 'Archive minutes',
        resourceReference: 'archive-drop:minutes/1',
        observedAt: $now,
        payload: 'minutes',
        provenance: new Provenance($connector->id(), $connector->version(), $installation->id, $now, $run->id),
    ));
    $reference = (string) $accepted->acceptedId();
    $runs->recordProgress($run, $attempt, [], ['accepted' => 1], [$reference]);
    $checkpoint = app(IngestionCheckpoints::class)->commit(
        $stream,
        Capability::IncrementalSync,
        'history',
        new CheckpointValue('archive.cursor', '1', 'page-4', CheckpointRule::Monotonic, 4),
        0,
        $run,
        [$reference],
        $attempt,
    );
    $runs->succeedAttempt($run, $attempt, ['accepted' => 1], [$reference]);
    $completedRun = $runs->find($run->id);
    $completedAttempt = $runs->attempt($attempt->id);
    app(SourceStreamStatuses::class)->project(
        $stream,
        $completedRun,
        $completedAttempt,
        new FreshnessExpectation(900, 3600),
        $completedRun->finishedAt,
    );
    $schedule = app(IngestionSchedules::class)->create(
        $installation->id,
        ConnectorCapability::DiscoversSources,
        '*/15 * * * *',
        'UTC',
        ['max_sources' => 25],
        $now,
    );

    $list = sourceLifecycleJson('aleph:sources');
    $shown = sourceLifecycleJson('aleph:source:show', ['installation' => $installation->id]);

    expect($list)->toHaveCount(2)
        ->and(collect($list)->pluck('label')->all())->toBe(['Primary archive', 'Secondary archive'])
        ->and($shown['installation']['id'])->toBe($installation->id)
        ->and($shown['installation']['owner'])->toBe('identity:user/mary')
        ->and($shown['health']['checks'][1]['check'])->toBe('authentication')
        ->and($shown['health']['checks'][1]['expires_at'])->toBe('2026-08-28T15:05:00+00:00')
        ->and($shown['streams'][0]['id'])->toBe($stream->id)
        ->and($shown['streams'][0]['status']['accepted_through_at'])->toBe($checkpoint->committedAt->format(DATE_ATOM))
        ->and($shown['streams'][0]['checkpoints'][0]['value']['value'])->toBe('page-4')
        ->and($shown['schedules'][0]['id'])->toBe($schedule->id)
        ->and($shown['schedules'][0]['next_due_at'])->toBe('2026-08-28T15:15:00+00:00')
        ->and($shown['runs'][0]['id'])->toBe($run->id)
        ->and($shown['runs'][0]['accepted_references'])->toBe([$reference]);
});

it('enables and disables a source installation and rejects unknown IDs', function (): void {
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installations = app(ConnectorInstallations::class);
    $installation = $installations->create($connector, 'Lifecycle source');

    expect(Artisan::call('aleph:source:disable', ['installation' => $installation->id]))->toBe(0)
        ->and(Artisan::output())->toContain('Disabled')
        ->and($installations->find($installation->id)?->enabled)->toBeFalse()
        ->and(Artisan::call('aleph:source:enable', ['installation' => $installation->id]))->toBe(0)
        ->and(Artisan::output())->toContain('Enabled')
        ->and($installations->find($installation->id)?->enabled)->toBeTrue();

    foreach (['aleph:source:show', 'aleph:source:enable', 'aleph:source:disable'] as $command) {
        $exit = Artisan::call($command, ['installation' => '01K4UNKNOWNSOURCE0000000']);

        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain('01K4UNKNOWNSOURCE0000000');
    }
});

it('dispatches due schedules through the host boundary', function (): void {
    $now = new DateTimeImmutable('2026-08-28T15:05:00+00:00');
    Carbon::setTestNow($now);
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'Scheduled source');
    $schedule = app(IngestionSchedules::class)->create(
        $installation->id,
        ConnectorCapability::DiscoversSources,
        '*/5 * * * *',
        'UTC',
        [],
        new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
    );
    app()->bind(
        ScheduledIngestionDispatcher::class,
        fn () => new SourceLifecycleScheduleDispatcher(app(IngestionRuns::class)),
    );

    $dispatched = sourceLifecycleJson('aleph:schedules:dispatch', ['owner' => 'scheduler:test']);

    expect($dispatched)->toHaveCount(1)
        ->and($dispatched[0]['schedule_id'])->toBe($schedule->id)
        ->and(app(IngestionRuns::class)->find($dispatched[0]['run_id']))->not->toBeNull();
});
