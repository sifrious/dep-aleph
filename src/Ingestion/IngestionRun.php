<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class IngestionRun
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $id,
        public string $sourceReference,
        public Capability $capability,
        public RunStatus $status,
        public array $parameters,
        public DateTimeImmutable $startedAt,
    ) {}
}
