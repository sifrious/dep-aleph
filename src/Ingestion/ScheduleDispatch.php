<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class ScheduleDispatch
{
    public function __construct(
        public string $id,
        public string $scheduleId,
        public string $runId,
        public DateTimeImmutable $dueAt,
        public DateTimeImmutable $dispatchedAt,
    ) {}
}
