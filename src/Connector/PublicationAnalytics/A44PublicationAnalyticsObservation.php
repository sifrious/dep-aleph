<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\PublicationAnalytics;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class A44PublicationAnalyticsObservation
{
    /**
     * @param  list<PublicationAnalyticsMetricObservation>  $metrics
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, mixed>  $freshness
     */
    public function __construct(
        public PublicationAnalyticsProvider $provider,
        public string $providerAccountReference,
        public string $publicationReference,
        public string $publicationKind,
        public ?string $publicationUrl,
        public DateTimeImmutable $windowStartAt,
        public DateTimeImmutable $windowEndAt,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $retrievedAt,
        public ?string $organicPaidClassification,
        public ?string $attributionScope,
        public ?string $attributionLimitations,
        public array $metrics,
        public string $rawPayloadReference,
        public string $normalizationVersion,
        public array $checkpoint,
        public array $freshness,
    ) {
        if (
            trim($providerAccountReference) === ''
            || trim($publicationReference) === ''
            || trim($publicationKind) === ''
            || trim($rawPayloadReference) === ''
            || trim($normalizationVersion) === ''
        ) {
            throw new InvalidArgumentException('A44 observations require provider/account identity, publication identity, payload identity, and normalization version.');
        }

        if ($windowEndAt < $windowStartAt) {
            throw new InvalidArgumentException('A44 observation windows must end on or after their start.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract' => 'A44',
            'provider' => $this->provider->value,
            'provider_account_reference' => $this->providerAccountReference,
            'publication_reference' => $this->publicationReference,
            'publication_kind' => $this->publicationKind,
            'publication_url' => $this->publicationUrl,
            'window_start_at' => $this->windowStartAt->format(DATE_ATOM),
            'window_end_at' => $this->windowEndAt->format(DATE_ATOM),
            'observed_at' => $this->observedAt->format(DATE_ATOM),
            'retrieved_at' => $this->retrievedAt->format(DATE_ATOM),
            'organic_paid_classification' => $this->organicPaidClassification,
            'attribution_scope' => $this->attributionScope,
            'attribution_limitations' => $this->attributionLimitations,
            'metrics' => array_map(
                static fn (PublicationAnalyticsMetricObservation $metric): array => $metric->toArray(),
                $this->metrics,
            ),
            'raw_payload_reference' => $this->rawPayloadReference,
            'normalization_version' => $this->normalizationVersion,
            'checkpoint' => $this->checkpoint,
            'freshness' => $this->freshness,
        ];
    }
}
