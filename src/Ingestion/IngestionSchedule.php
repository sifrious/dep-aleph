<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use Sifrious\Aleph\Connector\Capability;

final readonly class IngestionSchedule
{
    /**
     * @param  array<string, int|float|string|bool|null>  $constraints
     */
    public function __construct(
        public string $id,
        public string $sourceInstallationId,
        public Capability $capability,
        public string $cronExpression,
        public string $timezone,
        public bool $enabled,
        public DateTimeImmutable $nextDueAt,
        public ?DateTimeImmutable $lastDispatchedAt,
        public array $constraints,
        public ?string $lockedBy,
        public ?DateTimeImmutable $lockExpiresAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_installation_id' => $this->sourceInstallationId,
            'capability' => $this->capability->value,
            'cron_expression' => $this->cronExpression,
            'timezone' => $this->timezone,
            'enabled' => $this->enabled,
            'next_due_at' => $this->nextDueAt->format(DATE_ATOM),
            'last_dispatched_at' => $this->lastDispatchedAt?->format(DATE_ATOM),
            'constraints' => $this->constraints,
        ];
    }
}
