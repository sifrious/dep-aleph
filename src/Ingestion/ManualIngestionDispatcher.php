<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

interface ManualIngestionDispatcher
{
    public function dispatch(LaunchIngestionResult $launch): void;
}
