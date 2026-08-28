<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\Capability as ConnectorCapability;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Ingestion\Capability;
use Sifrious\Aleph\Ingestion\DispatchDueSchedules;
use Sifrious\Aleph\Ingestion\IngestionRun;
use Sifrious\Aleph\Ingestion\IngestionRuns;
use Sifrious\Aleph\Ingestion\IngestionSchedules;
use Sifrious\Aleph\Ingestion\IngestionTrigger;
use Sifrious\Aleph\Ingestion\ScheduledIngestion;
use Sifrious\Aleph\Ingestion\ScheduledIngestionDispatcher;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

final class ScheduleFakeDispatcher implements ScheduledIngestionDispatcher
{
    public int $dispatches = 0;

    public function __construct(private readonly IngestionRuns $runs) {}

    public function dispatch(ScheduledIngestion $ingestion): IngestionRun
    {
        $this->dispatches++;
        $schedule = $ingestion->schedule;

        return $this->runs->request(
            sourceReference: 'scheduled:'.$schedule->sourceInstallationId,
            capability: Capability::from($schedule->capability->value),
            parameters: $schedule->constraints,
            connectorId: 'archive-drop',
            sourceInstallationId: $schedule->sourceInstallationId,
            idempotencyKey: 'schedule:'.$schedule->id.':'.$ingestion->dueAt->format(DATE_ATOM),
            trigger: IngestionTrigger::Scheduled,
            requestedBy: 'schedule:'.$schedule->id,
            authorizationDecision: 'schedule-enabled:'.$schedule->id,
        );
    }
}

function scheduleFixture(): array
{
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installations = app(ConnectorInstallations::class);

    return [
        $installations->create($connector, 'First account'),
        $installations->create($connector, 'Second account'),
        app(IngestionSchedules::class),
    ];
}

it('schedules two accounts of one connector at different cadences without code changes', function (): void {
    [$first, $second, $schedules] = scheduleFixture();
    $at = new DateTimeImmutable('2026-08-28T14:00:00+00:00');
    $firstSchedule = $schedules->create(
        $first->id,
        ConnectorCapability::DiscoversSources,
        '*/15 * * * *',
        'UTC',
        ['max_sources' => 100],
        $at,
    );
    $secondSchedule = $schedules->create(
        $second->id,
        ConnectorCapability::DiscoversSources,
        '0 * * * *',
        'UTC',
        ['max_sources' => 500],
        $at,
    );
    $dispatcher = new ScheduleFakeDispatcher(app(IngestionRuns::class));
    $command = new DispatchDueSchedules($schedules, $dispatcher);
    $dispatched = $command->dispatch('scheduler:one', new DateTimeImmutable('2026-08-28T14:15:00+00:00'));
    $rescheduled = $schedules->reschedule(
        $secondSchedule,
        '30 * * * *',
        'UTC',
        ['max_sources' => 250],
        new DateTimeImmutable('2026-08-28T14:15:00+00:00'),
    );

    expect($firstSchedule->nextDueAt->format(DATE_ATOM))->toBe('2026-08-28T14:15:00+00:00')
        ->and($secondSchedule->nextDueAt->format(DATE_ATOM))->toBe('2026-08-28T15:00:00+00:00')
        ->and($dispatched)->toHaveCount(1)
        ->and($dispatched[0]->scheduleId)->toBe($firstSchedule->id)
        ->and($dispatcher->dispatches)->toBe(1)
        ->and($schedules->dispatches($firstSchedule))->toHaveCount(1)
        ->and($schedules->dispatches($secondSchedule))->toBe([])
        ->and($schedules->find($firstSchedule->id)?->nextDueAt->format(DATE_ATOM))->toBe('2026-08-28T14:30:00+00:00')
        ->and($rescheduled->nextDueAt->format(DATE_ATOM))->toBe('2026-08-28T14:30:00+00:00')
        ->and($rescheduled->constraints)->toBe(['max_sources' => 250])
        ->and($command->dispatch('scheduler:two', new DateTimeImmutable('2026-08-28T14:15:00+00:00')))->toBe([]);
});

it('validates supported operations cron expressions and timezones', function (): void {
    [$installation, , $schedules] = scheduleFixture();
    $at = new DateTimeImmutable('2026-08-28T12:30:00+00:00');
    $local = $schedules->create(
        $installation->id,
        ConnectorCapability::DiscoversSources,
        '0 9 * * *',
        'America/New_York',
        [],
        $at,
    );

    expect($local->nextDueAt->format(DATE_ATOM))->toBe('2026-08-28T13:00:00+00:00')
        ->and(fn () => $schedules->create(
            $installation->id,
            ConnectorCapability::Backfills,
            '0 * * * *',
            'UTC',
            [],
            $at,
        ))->toThrow(InvalidArgumentException::class, 'does not support')
        ->and(fn () => $schedules->create(
            $installation->id,
            ConnectorCapability::DiscoversSources,
            'not cron',
            'UTC',
            [],
            $at,
        ))->toThrow(InvalidArgumentException::class, 'cron expression')
        ->and(fn () => $schedules->create(
            $installation->id,
            ConnectorCapability::DiscoversSources,
            '0 * * * *',
            'Nowhere/Invalid',
            [],
            $at,
        ))->toThrow(Exception::class);
});

it('uses expiring distributed claims and respects disabled schedules', function (): void {
    [$installation, , $schedules] = scheduleFixture();
    $createdAt = new DateTimeImmutable('2026-08-28T14:00:00+00:00');
    $schedule = $schedules->create(
        $installation->id,
        ConnectorCapability::DiscoversSources,
        '*/5 * * * *',
        'UTC',
        [],
        $createdAt,
    );
    $dueAt = new DateTimeImmutable('2026-08-28T14:05:00+00:00');
    $firstClaim = $schedules->claimDue('scheduler:one', $dueAt, 1, 60);
    $competingClaim = $schedules->claimDue('scheduler:two', $dueAt, 1, 60);
    $expiredClaim = $schedules->claimDue('scheduler:two', $dueAt->modify('+61 seconds'), 1, 60);
    $schedules->disable($schedule, $dueAt->modify('+62 seconds'));

    expect($firstClaim)->toHaveCount(1)
        ->and($competingClaim)->toBe([])
        ->and($expiredClaim)->toHaveCount(1)
        ->and($expiredClaim[0]->lockedBy)->toBe('scheduler:two')
        ->and($schedules->claimDue('scheduler:three', $dueAt->modify('+180 seconds'), 1, 60))->toBe([]);
});
