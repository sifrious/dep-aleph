<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Envelope;

use DateTimeImmutable;

final readonly class Provenance
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $connectorId,
        public string $connectorVersion,
        public string $installationId,
        public DateTimeImmutable $capturedAt,
        public ?string $runId = null,
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'connector' => $this->connectorId,
            'connector_version' => $this->connectorVersion,
            'installation' => $this->installationId,
            'captured_at' => $this->capturedAt->format(DATE_ATOM),
            'run' => $this->runId,
            'details' => $this->details === [] ? null : $this->details,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
