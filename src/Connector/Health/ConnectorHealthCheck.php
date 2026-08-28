<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Health;

use DateTimeImmutable;

final readonly class ConnectorHealthCheck
{
    /**
     * @param  array<string, int|float|string|bool|null>  $metrics
     */
    public function __construct(
        public string $id,
        public string $sourceInstallationId,
        public HealthCheck $check,
        public HealthStatus $status,
        public string $message,
        public array $metrics,
        public ?HealthRemediation $remediation,
        public DateTimeImmutable $checkedAt,
        public DateTimeImmutable $expiresAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'check' => $this->check->value,
            'status' => $this->status->value,
            'message' => $this->message,
            'metrics' => $this->metrics,
            'remediation' => $this->remediation?->toArray(),
            'checked_at' => $this->checkedAt->format(DATE_ATOM),
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }
}
