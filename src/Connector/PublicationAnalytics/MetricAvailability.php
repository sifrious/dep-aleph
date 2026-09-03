<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\PublicationAnalytics;

enum MetricAvailability: string
{
    case Reported = 'reported';
    case Missing = 'missing';
    case Unavailable = 'unavailable';
}
