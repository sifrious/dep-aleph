<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use DateTimeImmutable;
use Sifrious\Aleph\Connector\Health\ConnectorHealthReport;
use Sifrious\Aleph\Ingestion\IngestionRunReadModel;
use Sifrious\Aleph\Ingestion\IngestionSchedule;

final readonly class SourceInstallationState
{
    /**
     * @param  list<array<string, mixed>>  $streams
     * @param  list<IngestionSchedule>  $schedules
     * @param  list<IngestionRunReadModel>  $runs
     */
    public function __construct(
        public ConnectorInstallation $installation,
        public ConnectorHealthReport $health,
        public array $streams,
        public array $schedules,
        public array $runs,
        public DateTimeImmutable $observedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $activeBackoffs = [];

        foreach ($this->runs as $run) {
            foreach ($run->attempts as $attempt) {
                if ($attempt->backoffUntil !== null && $attempt->backoffUntil > $this->observedAt) {
                    $activeBackoffs[] = [
                        'run_id' => $run->run->id,
                        'attempt_id' => $attempt->id,
                        'capability' => $run->run->capability->value,
                        'backoff_until' => $attempt->backoffUntil->format(DATE_ATOM),
                    ];
                }
            }
        }
        $backoffCapabilities = array_column($activeBackoffs, 'capability');

        return [
            'installation' => $this->installation->toArray(),
            'health' => $this->health->toArray(),
            'streams' => $this->streams,
            'schedules' => array_map(
                fn (IngestionSchedule $schedule): array => [
                    ...$schedule->toArray(),
                    'locked_by' => $schedule->lockedBy,
                    'lock_expires_at' => $schedule->lockExpiresAt?->format(DATE_ATOM),
                    'due' => $schedule->nextDueAt <= $this->observedAt,
                    'eligible' => $this->installation->enabled
                        && $schedule->enabled
                        && $schedule->nextDueAt <= $this->observedAt
                        && ($schedule->lockExpiresAt === null || $schedule->lockExpiresAt <= $this->observedAt)
                        && ! in_array($schedule->capability->value, $backoffCapabilities, true),
                ],
                $this->schedules,
            ),
            'runs' => array_map(
                static fn (IngestionRunReadModel $run): array => $run->toArray(),
                $this->runs,
            ),
            'active_backoffs' => $activeBackoffs,
            'observed_at' => $this->observedAt->format(DATE_ATOM),
        ];
    }
}
