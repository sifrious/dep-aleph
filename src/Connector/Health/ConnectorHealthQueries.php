<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Health;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallation;
use Sifrious\Aleph\Connector\ConnectorInstallations;

final readonly class ConnectorHealthQueries
{
    public function __construct(
        private ConnectorInstallations $installations,
        private ConnectorHealthChecks $checks,
    ) {}

    public function forInstallation(string $sourceInstallationId, DateTimeImmutable $now): ConnectorHealthReport
    {
        $installation = $this->installations->find($sourceInstallationId);

        if ($installation === null) {
            throw new InvalidArgumentException('The source installation does not exist.');
        }

        $checks = array_map(
            fn (HealthCheck $check): ConnectorHealthCheck => $this->current($installation, $check, $now),
            HealthCheck::cases(),
        );
        $status = array_reduce(
            $checks,
            static fn (HealthStatus $worst, ConnectorHealthCheck $check): HealthStatus => $check->status->severity() > $worst->severity()
                ? $check->status
                : $worst,
            HealthStatus::Healthy,
        );
        $worst = array_values(array_filter(
            $checks,
            static fn (ConnectorHealthCheck $check): bool => $check->status === $status,
        ));
        $summary = $status === HealthStatus::Healthy
            ? 'All current connector checks are healthy or not applicable.'
            : ($worst[0]->message ?? 'Connector health is unknown.');
        $remediations = [];

        foreach ($checks as $check) {
            if ($check->remediation !== null) {
                $remediations[$check->remediation->code] = $check->remediation;
            }
        }

        return new ConnectorHealthReport(
            $installation->id,
            $installation->connectorId,
            $status,
            $summary,
            $checks,
            array_values($remediations),
        );
    }

    private function current(
        ConnectorInstallation $installation,
        HealthCheck $check,
        DateTimeImmutable $now,
    ): ConnectorHealthCheck {
        if ($check === HealthCheck::Configuration && ! $installation->enabled) {
            return new ConnectorHealthCheck(
                '',
                $installation->id,
                $check,
                HealthStatus::Unhealthy,
                'The source installation is disabled.',
                [],
                new HealthRemediation('enable_installation', 'Enable the source installation after resolving its configuration.'),
                $now,
                $now,
            );
        }

        $latest = $this->checks->latest($installation->id, $check);

        if ($latest === null) {
            return new ConnectorHealthCheck(
                '',
                $installation->id,
                $check,
                HealthStatus::Unknown,
                "The {$check->value} check has not run.",
                [],
                new HealthRemediation('run_'.$check->value.'_check', "Run the {$check->value} health check."),
                $now,
                $now,
            );
        }

        if ($latest->expiresAt <= $now) {
            return new ConnectorHealthCheck(
                $latest->id,
                $latest->sourceInstallationId,
                $latest->check,
                HealthStatus::Unknown,
                "The {$check->value} check expired.",
                $latest->metrics,
                new HealthRemediation('refresh_'.$check->value.'_check', "Run the {$check->value} health check again."),
                $latest->checkedAt,
                $latest->expiresAt,
            );
        }

        return $latest;
    }
}
