<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Console;

use DateTimeImmutable;
use Sifrious\Aleph\Ingestion\DispatchDueSchedules;
use Throwable;

final class DispatchSchedulesCommand extends AlephCommand
{
    protected $signature = 'aleph:schedules:dispatch
        {owner : Stable scheduler owner reference}
        {--limit=100 : Maximum due schedules to claim}
        {--lock-seconds=60 : Claim lease duration}
        {--json : Emit JSON}';

    protected $description = 'Claim due schedules and delegate them to the host dispatcher.';

    public function handle(DispatchDueSchedules $dispatch): int
    {
        try {
            $results = $dispatch->dispatch(
                (string) $this->argument('owner'),
                new DateTimeImmutable,
                (int) $this->option('limit'),
                (int) $this->option('lock-seconds'),
            );
        } catch (Throwable $failure) {
            return $this->failure($failure);
        }

        $data = array_map(static fn ($result): array => [
            'id' => $result->id,
            'schedule_id' => $result->scheduleId,
            'run_id' => $result->runId,
            'due_at' => $result->dueAt->format(DATE_ATOM),
            'dispatched_at' => $result->dispatchedAt->format(DATE_ATOM),
        ], $results);

        if ((bool) $this->option('json')) {
            return $this->json($data);
        }

        $this->table(['Schedule', 'Run', 'Due'], array_map(
            static fn (array $result): array => [$result['schedule_id'], $result['run_id'], $result['due_at']],
            $data,
        ));

        return self::SUCCESS;
    }
}
