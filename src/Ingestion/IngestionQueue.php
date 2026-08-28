<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

interface IngestionQueue
{
    public function dispatch(QueuedIngestion $ingestion): QueueReceipt;
}
