<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Health;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use stdClass;

final readonly class ConnectorHealthChecks
{
    public function __construct(
        private ConnectionInterface $connection,
        private ConnectorInstallations $installations,
    ) {}

    /**
     * @param  array<string, int|float|string|bool|null>  $metrics
     */
    public function record(
        string $sourceInstallationId,
        HealthCheck $check,
        HealthStatus $status,
        string $message,
        array $metrics,
        ?HealthRemediation $remediation,
        DateTimeImmutable $checkedAt,
        DateTimeImmutable $expiresAt,
    ): ConnectorHealthCheck {
        if ($this->installations->find($sourceInstallationId) === null) {
            throw new InvalidArgumentException('The source installation does not exist.');
        }

        if (trim($message) === '' || $expiresAt <= $checkedAt) {
            throw new InvalidArgumentException('A health check requires a message and a future expiry.');
        }

        json_encode($metrics, JSON_THROW_ON_ERROR);
        $id = (string) Str::ulid();
        $this->table()->insert([
            'id' => $id,
            'source_installation_id' => $sourceInstallationId,
            'check' => $check->value,
            'status' => $status->value,
            'message' => $message,
            'metrics' => $metrics === [] ? null : json_encode($metrics, JSON_THROW_ON_ERROR),
            'remediation' => $remediation === null ? null : json_encode($remediation->toArray(), JSON_THROW_ON_ERROR),
            'checked_at' => $checkedAt,
            'expires_at' => $expiresAt,
        ]);

        return $this->find($id) ?? throw new InvalidArgumentException('The health check could not be read back.');
    }

    public function latest(string $sourceInstallationId, HealthCheck $check): ?ConnectorHealthCheck
    {
        $row = $this->table()
            ->where('source_installation_id', $sourceInstallationId)
            ->where('check', $check->value)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * @return list<ConnectorHealthCheck>
     */
    public function history(string $sourceInstallationId, HealthCheck $check): array
    {
        return array_values($this->table()
            ->where('source_installation_id', $sourceInstallationId)
            ->where('check', $check->value)
            ->orderBy('checked_at')
            ->get()
            ->map(fn (stdClass $row): ConnectorHealthCheck => $this->hydrate($row))
            ->all());
    }

    private function find(string $id): ?ConnectorHealthCheck
    {
        $row = $this->table()->where('id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function hydrate(stdClass $row): ConnectorHealthCheck
    {
        $metrics = $row->metrics === null ? [] : json_decode((string) $row->metrics, true, 512, JSON_THROW_ON_ERROR);
        $remediation = $row->remediation === null ? null : json_decode((string) $row->remediation, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($metrics) || ($remediation !== null && ! is_array($remediation))) {
            throw new JsonException('Stored health details must be JSON objects.');
        }

        return new ConnectorHealthCheck(
            id: (string) $row->id,
            sourceInstallationId: (string) $row->source_installation_id,
            check: HealthCheck::from((string) $row->check),
            status: HealthStatus::from((string) $row->status),
            message: (string) $row->message,
            metrics: $metrics,
            remediation: $remediation === null ? null : new HealthRemediation(
                (string) ($remediation['code'] ?? ''),
                (string) ($remediation['message'] ?? ''),
            ),
            checkedAt: new DateTimeImmutable((string) $row->checked_at),
            expiresAt: new DateTimeImmutable((string) $row->expires_at),
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_connector_health_checks');
    }
}
