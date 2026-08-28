<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use Throwable;

final readonly class DispatchDueSchedules
{
    public function __construct(
        private IngestionSchedules $schedules,
        private ScheduledIngestionDispatcher $dispatcher,
    ) {}

    /**
     * @return list<ScheduleDispatch>
     */
    public function dispatch(
        string $owner,
        DateTimeImmutable $now,
        int $limit = 100,
        int $lockSeconds = 60,
    ): array {
        $dispatched = [];

        foreach ($this->schedules->claimDue($owner, $now, $limit, $lockSeconds) as $schedule) {
            $dueAt = $schedule->nextDueAt;

            try {
                $run = $this->dispatcher->dispatch(new ScheduledIngestion($schedule, $dueAt));
                $dispatched[] = $this->schedules->recordDispatch($schedule, $run, $owner, $dueAt, $now);
            } catch (Throwable $failure) {
                $this->schedules->release($schedule, $owner, $now);

                throw $failure;
            }
        }

        return $dispatched;
    }
}
