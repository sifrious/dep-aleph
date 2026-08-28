<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Inventory;

use DateTimeImmutable;
use DateTimeZone;
use Sifrious\Aleph\Extraction\ExtractionStatus;
use Sifrious\Aleph\Web\DiscoveryOrigin;
use Sifrious\Aleph\Web\FetchFailure;
use Sifrious\Aleph\Web\FrontierState;
use Sifrious\Aleph\Web\SkipReason;
use Sifrious\Funes\Value\ObservationDisposition;

final readonly class InventoryResource
{
    public function __construct(
        public string $canonicalUrl,
        public string $canonicalHash,
        public string $requestedUrl,
        public ?string $finalUrl,
        public string $host,
        public int $depth,
        public DiscoveryOrigin $origin,
        public ?string $parentCanonicalUrl,
        public FrontierState $state,
        public ?SkipReason $skipReason,
        public bool $external,
        public ?int $httpStatus,
        public ?string $contentType,
        public ?FetchFailure $failure,
        public ?string $failureMessage,
        public ?string $observationId,
        public ?ObservationDisposition $disposition,
        public ?string $payloadHash,
        public ?int $byteSize,
        public ?DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $ingestedAt,
        public ?DateTimeImmutable $lastObservedAt,
        public ?string $extractor,
        public ?string $extractionVersion,
        public ?ExtractionStatus $extractionStatus,
        public ?string $extractionError,
        public bool $calendarLike,
        public ?CalendarSignal $calendarSignal,
        public Freshness $freshness,
    ) {}

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'canonical_url',
            'canonical_hash',
            'requested_url',
            'final_url',
            'host',
            'depth',
            'origin',
            'parent_canonical_url',
            'state',
            'skip_reason',
            'external',
            'http_status',
            'content_type',
            'failure',
            'failure_message',
            'observation_id',
            'observation_disposition',
            'payload_hash',
            'byte_size',
            'observed_at',
            'ingested_at',
            'last_observed_at',
            'extractor',
            'extraction_version',
            'extraction_status',
            'extraction_error',
            'calendar_like',
            'calendar_signal',
            'freshness',
        ];
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function toArray(): array
    {
        return [
            'canonical_url' => $this->canonicalUrl,
            'canonical_hash' => $this->canonicalHash,
            'requested_url' => $this->requestedUrl,
            'final_url' => $this->finalUrl,
            'host' => $this->host,
            'depth' => $this->depth,
            'origin' => $this->origin->value,
            'parent_canonical_url' => $this->parentCanonicalUrl,
            'state' => $this->state->value,
            'skip_reason' => $this->skipReason?->value,
            'external' => $this->external,
            'http_status' => $this->httpStatus,
            'content_type' => $this->contentType,
            'failure' => $this->failure?->value,
            'failure_message' => $this->failureMessage,
            'observation_id' => $this->observationId,
            'observation_disposition' => $this->disposition?->value,
            'payload_hash' => $this->payloadHash,
            'byte_size' => $this->byteSize,
            'observed_at' => self::instant($this->observedAt),
            'ingested_at' => self::instant($this->ingestedAt),
            'last_observed_at' => self::instant($this->lastObservedAt),
            'extractor' => $this->extractor,
            'extraction_version' => $this->extractionVersion,
            'extraction_status' => $this->extractionStatus?->value,
            'extraction_error' => $this->extractionError,
            'calendar_like' => $this->calendarLike,
            'calendar_signal' => $this->calendarSignal?->value,
            'freshness' => $this->freshness->value,
        ];
    }

    public static function instant(?DateTimeImmutable $moment): ?string
    {
        return $moment?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
