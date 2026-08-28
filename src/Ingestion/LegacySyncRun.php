<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;

final readonly class LegacySyncRun
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, int|float>  $stats
     * @param  list<string>  $acceptedReferences
     */
    public function __construct(
        public string $legacyReference,
        public string $connectorId,
        public string $sourceReference,
        public Capability $capability,
        public RunStatus $status,
        public RunCompleteness $completeness,
        public array $parameters,
        public array $checkpoint,
        public array $stats,
        public ?RunFailure $failure,
        public array $acceptedReferences,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?string $sourceInstallationId = null,
    ) {}
}
