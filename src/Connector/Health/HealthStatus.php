<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Health;

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
    case Unknown = 'unknown';
    case NotApplicable = 'not_applicable';

    public function severity(): int
    {
        return match ($this) {
            self::Unhealthy => 3,
            self::Degraded => 2,
            self::Unknown => 1,
            self::Healthy, self::NotApplicable => 0,
        };
    }
}
