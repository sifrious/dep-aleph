<?php

declare(strict_types=1);

use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Health\ConnectorHealthChecks;
use Sifrious\Aleph\Connector\Health\ConnectorHealthQueries;
use Sifrious\Aleph\Connector\Health\HealthCheck;
use Sifrious\Aleph\Connector\Health\HealthRemediation;
use Sifrious\Aleph\Connector\Health\HealthStatus;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

function healthInstallation(): array
{
    $connector = new DiscoveryAndDownloadConnector;
    app(ConnectorRegistry::class)->register($connector);
    $installation = app(ConnectorInstallations::class)->create($connector, 'Health source');

    return [$installation, app(ConnectorHealthChecks::class), app(ConnectorHealthQueries::class)];
}

it('explains every health dimension and its remediation without logs', function (): void {
    [$installation, $checks, $queries] = healthInstallation();
    $checkedAt = new DateTimeImmutable('2026-08-28T15:00:00+00:00');
    $expiresAt = new DateTimeImmutable('2026-08-28T15:05:00+00:00');

    foreach (HealthCheck::cases() as $check) {
        $status = $check === HealthCheck::Authentication ? HealthStatus::Unhealthy : HealthStatus::Healthy;
        $checks->record(
            $installation->id,
            $check,
            $status,
            $check === HealthCheck::Authentication ? 'The provider rejected the stored credentials.' : "The {$check->value} check passed.",
            $check === HealthCheck::Backlog ? ['pending' => 0] : [],
            $check === HealthCheck::Authentication
                ? new HealthRemediation('replace_credentials', 'Replace the provider credentials and run the check again.')
                : null,
            $checkedAt,
            $expiresAt,
        );
    }

    $report = $queries->forInstallation($installation->id, new DateTimeImmutable('2026-08-28T15:01:00+00:00'));
    $serialized = $report->toArray();

    expect($report->status)->toBe(HealthStatus::Unhealthy)
        ->and($report->summary)->toBe('The provider rejected the stored credentials.')
        ->and($report->checks)->toHaveCount(count(HealthCheck::cases()))
        ->and(array_map(fn ($check) => $check->check, $report->checks))->toBe(HealthCheck::cases())
        ->and($serialized['remediations'])->toBe([[
            'code' => 'replace_credentials',
            'message' => 'Replace the provider credentials and run the check again.',
        ]])
        ->and($serialized['checks'][6]['metrics'])->toBe(['pending' => 0]);
});

it('makes missing and expired checks explicitly unknown and actionable', function (): void {
    [$installation, $checks, $queries] = healthInstallation();
    $checks->record(
        $installation->id,
        HealthCheck::RateLimit,
        HealthStatus::Healthy,
        'Rate capacity is available.',
        ['remaining' => 100],
        null,
        new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
        new DateTimeImmutable('2026-08-28T15:01:00+00:00'),
    );
    $report = $queries->forInstallation($installation->id, new DateTimeImmutable('2026-08-28T15:02:00+00:00'));
    $rate = $report->checks[3];
    $authentication = $report->checks[1];

    expect($report->status)->toBe(HealthStatus::Unknown)
        ->and($rate->status)->toBe(HealthStatus::Unknown)
        ->and($rate->message)->toContain('expired')
        ->and($rate->metrics)->toBe(['remaining' => 100])
        ->and($rate->remediation?->code)->toBe('refresh_rate_limit_check')
        ->and($authentication->status)->toBe(HealthStatus::Unknown)
        ->and($authentication->message)->toContain('has not run')
        ->and($authentication->remediation?->code)->toBe('run_authentication_check');
});

it('reports a disabled installation as unhealthy regardless of an older configuration check', function (): void {
    [$installation, $checks, $queries] = healthInstallation();
    $checks->record(
        $installation->id,
        HealthCheck::Configuration,
        HealthStatus::Healthy,
        'Configuration is valid.',
        [],
        null,
        new DateTimeImmutable('2026-08-28T15:00:00+00:00'),
        new DateTimeImmutable('2026-08-28T16:00:00+00:00'),
    );
    app(ConnectorInstallations::class)->disable($installation->id);
    $report = $queries->forInstallation($installation->id, new DateTimeImmutable('2026-08-28T15:01:00+00:00'));

    expect($report->status)->toBe(HealthStatus::Unhealthy)
        ->and($report->checks[0]->message)->toBe('The source installation is disabled.')
        ->and($report->checks[0]->remediation?->code)->toBe('enable_installation')
        ->and($checks->history($installation->id, HealthCheck::Configuration))->toHaveCount(1);
});
