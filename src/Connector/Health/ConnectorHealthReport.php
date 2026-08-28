<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Health;

final readonly class ConnectorHealthReport
{
    /**
     * @param  list<ConnectorHealthCheck>  $checks
     * @param  list<HealthRemediation>  $remediations
     */
    public function __construct(
        public string $sourceInstallationId,
        public string $connectorId,
        public HealthStatus $status,
        public string $summary,
        public array $checks,
        public array $remediations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_installation_id' => $this->sourceInstallationId,
            'connector' => $this->connectorId,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'checks' => array_map(
                static fn (ConnectorHealthCheck $check): array => $check->toArray(),
                $this->checks,
            ),
            'remediations' => array_map(
                static fn (HealthRemediation $remediation): array => $remediation->toArray(),
                $this->remediations,
            ),
        ];
    }
}
