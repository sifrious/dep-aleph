<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Sifrious\Aleph\Connector\Capability as ConnectorCapability;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use stdClass;

final readonly class IngestionSchedules
{
    public function __construct(
        private ConnectionInterface $connection,
        private ConnectorInstallations $installations,
        private ConnectorRegistry $connectors,
    ) {}

    /**
     * @param  array<string, int|float|string|bool|null>  $constraints
     */
    public function create(
        string $sourceInstallationId,
        ConnectorCapability $capability,
        string $cronExpression,
        string $timezone,
        array $constraints,
        DateTimeImmutable $at,
    ): IngestionSchedule {
        $installation = $this->installations->find($sourceInstallationId);

        if ($installation === null || ! $installation->enabled) {
            throw new InvalidArgumentException('A schedule requires an enabled source installation.');
        }

        if (! $this->connectors->has($installation->connectorId)
            || ! $capability->isDispatchable()
            || ! $this->connectors->manifest($installation->connectorId)->supports($capability)
        ) {
            throw new InvalidArgumentException('The connector does not support this scheduled operation.');
        }

        if (! CronExpression::isValidExpression($cronExpression)) {
            throw new InvalidArgumentException('The cron expression is invalid.');
        }

        new DateTimeZone($timezone);
        json_encode($constraints, JSON_THROW_ON_ERROR);
        $id = (string) Str::ulid();
        $this->table()->insert([
            'id' => $id,
            'source_installation_id' => $sourceInstallationId,
            'capability' => $capability->value,
            'cron_expression' => $cronExpression,
            'timezone' => $timezone,
            'enabled' => true,
            'next_due_at' => $this->nextDue($cronExpression, $timezone, $at),
            'last_dispatched_at' => null,
            'constraints' => $constraints === [] ? null : json_encode($constraints, JSON_THROW_ON_ERROR),
            'locked_by' => null,
            'lock_expires_at' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $this->find($id) ?? throw new InvalidArgumentException('The ingestion schedule could not be read back.');
    }

    public function find(string $id): ?IngestionSchedule
    {
        $row = $this->table()->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * @return list<IngestionSchedule>
     */
    public function forInstallation(string $sourceInstallationId): array
    {
        return array_values($this->table()
            ->where('source_installation_id', $sourceInstallationId)
            ->orderBy('capability')
            ->get()
            ->map(fn (stdClass $row): IngestionSchedule => $this->hydrate($row))
            ->all());
    }

    public function disable(IngestionSchedule $schedule, DateTimeImmutable $at): void
    {
        $this->table()->where('id', $schedule->id)->update([
            'enabled' => false,
            'locked_by' => null,
            'lock_expires_at' => null,
            'updated_at' => $at,
        ]);
    }

    public function enable(IngestionSchedule $schedule, DateTimeImmutable $at): IngestionSchedule
    {
        $this->table()->where('id', $schedule->id)->update([
            'enabled' => true,
            'next_due_at' => $this->nextDue($schedule->cronExpression, $schedule->timezone, $at),
            'updated_at' => $at,
        ]);

        return $this->find($schedule->id) ?? throw new InvalidArgumentException('The ingestion schedule could not be read back.');
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $constraints
     */
    public function reschedule(
        IngestionSchedule $schedule,
        string $cronExpression,
        string $timezone,
        array $constraints,
        DateTimeImmutable $at,
    ): IngestionSchedule {
        if (! CronExpression::isValidExpression($cronExpression)) {
            throw new InvalidArgumentException('The cron expression is invalid.');
        }

        new DateTimeZone($timezone);
        json_encode($constraints, JSON_THROW_ON_ERROR);
        $this->table()->where('id', $schedule->id)->update([
            'cron_expression' => $cronExpression,
            'timezone' => $timezone,
            'constraints' => $constraints === [] ? null : json_encode($constraints, JSON_THROW_ON_ERROR),
            'next_due_at' => $this->nextDue($cronExpression, $timezone, $at),
            'locked_by' => null,
            'lock_expires_at' => null,
            'updated_at' => $at,
        ]);

        return $this->find($schedule->id) ?? throw new InvalidArgumentException('The ingestion schedule could not be read back.');
    }

    /**
     * @return list<IngestionSchedule>
     */
    public function claimDue(string $owner, DateTimeImmutable $now, int $limit, int $lockSeconds): array
    {
        if (trim($owner) === '' || $limit < 1 || $lockSeconds < 1) {
            throw new InvalidArgumentException('Schedule claims require an owner and positive limits.');
        }

        return $this->connection->transaction(function () use ($owner, $now, $limit, $lockSeconds): array {
            $rows = $this->table()
                ->where('enabled', true)
                ->where('next_due_at', '<=', $now)
                ->where(fn (Builder $query): Builder => $query->whereNull('lock_expires_at')->orWhere('lock_expires_at', '<=', $now))
                ->orderBy('next_due_at')
                ->limit($limit)
                ->get();
            $claimed = [];

            foreach ($rows as $row) {
                $updated = $this->table()
                    ->where('id', $row->id)
                    ->where(fn (Builder $query): Builder => $query->whereNull('lock_expires_at')->orWhere('lock_expires_at', '<=', $now))
                    ->update([
                        'locked_by' => $owner,
                        'lock_expires_at' => $now->modify("+{$lockSeconds} seconds"),
                        'updated_at' => $now,
                    ]);

                if ($updated === 1) {
                    $claimed[] = $this->find((string) $row->id);
                }
            }

            return array_values(array_filter($claimed));
        });
    }

    public function recordDispatch(
        IngestionSchedule $schedule,
        IngestionRun $run,
        string $owner,
        DateTimeImmutable $dueAt,
        DateTimeImmutable $dispatchedAt,
    ): ScheduleDispatch {
        $existing = $this->dispatchFor($schedule, $dueAt);

        if ($existing !== null) {
            return $existing;
        }

        $current = $this->find($schedule->id);

        if ($current === null || $current->lockedBy !== $owner) {
            throw new InvalidArgumentException('The schedule dispatch lock is not owned by this dispatcher.');
        }

        if ($run->sourceInstallationId !== $schedule->sourceInstallationId || $run->capability->value !== $schedule->capability->value) {
            throw new InvalidArgumentException('The dispatched run does not match the schedule.');
        }

        $id = (string) Str::ulid();
        $this->connection->transaction(function () use ($id, $schedule, $run, $dueAt, $dispatchedAt): void {
            $this->connection->table('aleph_ingestion_schedule_dispatches')->insert([
                'id' => $id,
                'schedule_id' => $schedule->id,
                'run_id' => $run->id,
                'due_at' => $dueAt,
                'dispatched_at' => $dispatchedAt,
            ]);
            $this->table()->where('id', $schedule->id)->update([
                'last_dispatched_at' => $dispatchedAt,
                'next_due_at' => $this->nextDue($schedule->cronExpression, $schedule->timezone, $dispatchedAt),
                'locked_by' => null,
                'lock_expires_at' => null,
                'updated_at' => $dispatchedAt,
            ]);
        });

        return $this->findDispatch($id) ?? throw new InvalidArgumentException('The schedule dispatch could not be read back.');
    }

    /**
     * @return list<ScheduleDispatch>
     */
    public function dispatches(IngestionSchedule $schedule): array
    {
        return array_values($this->connection->table('aleph_ingestion_schedule_dispatches')
            ->where('schedule_id', $schedule->id)
            ->orderBy('due_at')
            ->get()
            ->map(fn (stdClass $row): ScheduleDispatch => $this->hydrateDispatch($row))
            ->all());
    }

    public function release(IngestionSchedule $schedule, string $owner, DateTimeImmutable $at): void
    {
        $this->table()->where('id', $schedule->id)->where('locked_by', $owner)->update([
            'locked_by' => null,
            'lock_expires_at' => null,
            'updated_at' => $at,
        ]);
    }

    private function dispatchFor(IngestionSchedule $schedule, DateTimeImmutable $dueAt): ?ScheduleDispatch
    {
        $row = $this->connection->table('aleph_ingestion_schedule_dispatches')
            ->where('schedule_id', $schedule->id)
            ->where('due_at', $dueAt)
            ->first();

        return $row instanceof stdClass ? $this->hydrateDispatch($row) : null;
    }

    private function findDispatch(string $id): ?ScheduleDispatch
    {
        $row = $this->connection->table('aleph_ingestion_schedule_dispatches')->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrateDispatch($row) : null;
    }

    private function nextDue(string $expression, string $timezone, DateTimeImmutable $after): DateTimeImmutable
    {
        $next = (new CronExpression($expression))->getNextRunDate($after, 0, false, $timezone);

        return DateTimeImmutable::createFromMutable($next)->setTimezone(new DateTimeZone('UTC'));
    }

    private function hydrate(stdClass $row): IngestionSchedule
    {
        $constraints = $row->constraints === null ? [] : json_decode((string) $row->constraints, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($constraints)) {
            throw new JsonException('Stored schedule constraints must be a JSON object.');
        }

        return new IngestionSchedule(
            id: (string) $row->id,
            sourceInstallationId: (string) $row->source_installation_id,
            capability: ConnectorCapability::from((string) $row->capability),
            cronExpression: (string) $row->cron_expression,
            timezone: (string) $row->timezone,
            enabled: (bool) $row->enabled,
            nextDueAt: new DateTimeImmutable((string) $row->next_due_at),
            lastDispatchedAt: $row->last_dispatched_at === null ? null : new DateTimeImmutable((string) $row->last_dispatched_at),
            constraints: $constraints,
            lockedBy: $row->locked_by === null ? null : (string) $row->locked_by,
            lockExpiresAt: $row->lock_expires_at === null ? null : new DateTimeImmutable((string) $row->lock_expires_at),
        );
    }

    private function hydrateDispatch(stdClass $row): ScheduleDispatch
    {
        return new ScheduleDispatch(
            (string) $row->id,
            (string) $row->schedule_id,
            (string) $row->run_id,
            new DateTimeImmutable((string) $row->due_at),
            new DateTimeImmutable((string) $row->dispatched_at),
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_ingestion_schedules');
    }
}
