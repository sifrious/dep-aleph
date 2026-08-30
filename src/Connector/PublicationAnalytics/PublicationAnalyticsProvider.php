<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\PublicationAnalytics;

enum PublicationAnalyticsProvider: string
{
    case X = 'x';
    case YouTube = 'youtube';
    case Web = 'web';
}
