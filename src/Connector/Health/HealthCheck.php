<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Health;

enum HealthCheck: string
{
    case Configuration = 'configuration';
    case Authentication = 'authentication';
    case Reachability = 'reachability';
    case RateLimit = 'rate_limit';
    case Freshness = 'freshness';
    case Webhook = 'webhook';
    case Backlog = 'backlog';
    case Queue = 'queue';
    case Funes = 'funes';
    case Storage = 'storage';
}
