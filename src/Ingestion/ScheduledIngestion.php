<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class ScheduledIngestion
{
    public function __construct(
        public IngestionSchedule $schedule,
        public DateTimeImmutable $dueAt,
    ) {}
}
