<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\PublicationAnalytics;

use InvalidArgumentException;

final readonly class PublicationAnalyticsMetricObservation
{
    /**
     * @param  list<string>  $derivedFromSourceMetrics
     */
    public function __construct(
        public string $sourceMetricKey,
        public ?string $sourceMetricDefinition,
        public ?string $sourceMetricVersion,
        public ?string $sourceApiVersion,
        public MetricAvailability $availability,
        public ?float $value,
        public string $unit,
        public ?string $normalizedMetricKey = null,
        public array $derivedFromSourceMetrics = [],
    ) {
        if (trim($sourceMetricKey) === '') {
            throw new InvalidArgumentException('Publication analytics metrics require a source metric key.');
        }

        if (trim($unit) === '') {
            throw new InvalidArgumentException('Publication analytics metrics require a unit.');
        }

        if ($availability === MetricAvailability::Reported && $value === null) {
            throw new InvalidArgumentException('Reported publication analytics metrics require a numeric value.');
        }

        if ($availability !== MetricAvailability::Reported && $value !== null) {
            throw new InvalidArgumentException('Missing or unavailable metrics must not invent numeric values.');
        }

        foreach ($derivedFromSourceMetrics as $metric) {
            if (trim($metric) === '') {
                throw new InvalidArgumentException('Derived metric dependencies must be non-empty source metric keys.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_metric_key' => $this->sourceMetricKey,
            'source_metric_definition' => $this->sourceMetricDefinition,
            'source_metric_version' => $this->sourceMetricVersion,
            'source_api_version' => $this->sourceApiVersion,
            'availability' => $this->availability->value,
            'value' => $this->value,
            'unit' => $this->unit,
            'normalized_metric_key' => $this->normalizedMetricKey,
            'derived_from_source_metrics' => $this->derivedFromSourceMetrics,
        ];
    }
}
