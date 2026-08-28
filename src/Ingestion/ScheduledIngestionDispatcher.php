<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

interface ScheduledIngestionDispatcher
{
    public function dispatch(ScheduledIngestion $ingestion): IngestionRun;
}
