<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Inventory;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Sifrious\Aleph\Extraction\ExtractionStatus;
use Sifrious\Aleph\Ingestion\IngestionRun;
use Sifrious\Aleph\Ingestion\RunStatus;
use Sifrious\Aleph\Web\DiscoveryOrigin;
use Sifrious\Aleph\Web\FetchFailure;
use Sifrious\Aleph\Web\FrontierState;
use Sifrious\Aleph\Web\SkipReason;
use Sifrious\Aleph\Web\WebSource;
use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Value\Observation;
use Sifrious\Funes\Value\ObservationDisposition;
use stdClass;

final readonly class InventoryReader
{
    public function __construct(
        private ConnectionInterface $connection,
        private ObservationStore $observations,
    ) {}

    public function read(WebSource $source, IngestionRun $run): Inventory
    {
        $rows = $this->rows($run);
        $calendarPaths = $this->calendarPaths($source, $rows);
        $resources = [];

        foreach ($rows as $row) {
            $resources[] = $this->resource($source, $run, $row, $calendarPaths);
        }

        usort($resources, fn (InventoryResource $a, InventoryResource $b): int => strcmp($a->canonicalUrl, $b->canonicalUrl));

        return new Inventory($this->bounds($source, $run), $resources);
    }

    /**
     * @return list<stdClass>
     */
    private function rows(IngestionRun $run): array
    {
        $rows = $this->table()
            ->leftJoin('aleph_frontier_candidates as parents', 'parents.id', '=', 'candidates.parent_id')
            ->where('candidates.run_id', $run->id)
            ->orderBy('candidates.id')
            ->get(['candidates.*', 'parents.canonical_url as parent_canonical_url'])
            ->all();

        return array_values($rows);
    }

    /**
     * @param  list<stdClass>  $rows
     * @return array<int, bool>
     */
    private function calendarPaths(WebSource $source, array $rows): array
    {
        $canonicalizer = $source->canonicalizer();
        $calendars = [];

        foreach ($rows as $row) {
            $url = $canonicalizer->canonicalize((string) $row->canonical_url);
            $calendars[(int) $row->id] = $url !== null && $source->looksLikeCalendar($url);
        }

        return $calendars;
    }

    /**
     * @param  array<int, bool>  $calendarPaths
     */
    private function resource(WebSource $source, IngestionRun $run, stdClass $row, array $calendarPaths): InventoryResource
    {
        $observationId = $row->observation_id === null ? null : (string) $row->observation_id;
        $payloadHash = $row->payload_hash === null ? null : (string) $row->payload_hash;
        $byteSize = $row->byte_size === null ? null : (int) $row->byte_size;
        $ingestedAt = $this->moment($row->ingested_at);
        $lastObservedAt = $this->moment($row->observed_at);
        $freshness = Freshness::Current;

        if ($observationId === null) {
            $previous = $this->previousObservation($source, (string) $row->canonical_url);
            $freshness = $previous === null ? Freshness::Unobserved : Freshness::Stale;
            $observationId = $previous?->id;
            $payloadHash = $previous?->payloadHash;
            $byteSize = $previous === null ? null : strlen($previous->payload);
            $ingestedAt = $previous?->ingestedAt;
            $lastObservedAt = $previous?->observedAt;
        }

        [$calendarLike, $calendarSignal] = $this->calendar($row, $calendarPaths);

        return new InventoryResource(
            canonicalUrl: (string) $row->canonical_url,
            canonicalHash: (string) $row->canonical_hash,
            requestedUrl: (string) $row->requested_url,
            finalUrl: $row->final_url === null ? null : (string) $row->final_url,
            host: (string) $row->host,
            depth: (int) $row->depth,
            origin: DiscoveryOrigin::from((string) $row->origin),
            parentCanonicalUrl: $row->parent_canonical_url === null ? null : (string) $row->parent_canonical_url,
            state: FrontierState::from((string) $row->state),
            skipReason: $row->skip_reason === null ? null : SkipReason::from((string) $row->skip_reason),
            external: ! $source->hosts->allows((string) $row->host),
            httpStatus: $row->http_status === null ? null : (int) $row->http_status,
            contentType: $row->content_type === null ? null : (string) $row->content_type,
            failure: $row->failure === null ? null : FetchFailure::from((string) $row->failure),
            failureMessage: $row->failure_message === null ? null : (string) $row->failure_message,
            observationId: $observationId,
            disposition: $row->observation_disposition === null
                ? null
                : ObservationDisposition::from((string) $row->observation_disposition),
            payloadHash: $payloadHash,
            byteSize: $byteSize,
            observedAt: $this->moment($row->observed_at),
            ingestedAt: $ingestedAt,
            lastObservedAt: $lastObservedAt,
            extractor: $row->extractor === null ? null : (string) $row->extractor,
            extractionVersion: $row->extraction_version === null ? null : (string) $row->extraction_version,
            extractionStatus: $row->extraction_status === null ? null : ExtractionStatus::from((string) $row->extraction_status),
            extractionError: $row->extraction_error === null ? null : (string) $row->extraction_error,
            calendarLike: $calendarLike,
            calendarSignal: $calendarSignal,
            freshness: $freshness,
        );
    }

    /**
     * @param  array<int, bool>  $calendarPaths
     * @return array{bool, ?CalendarSignal}
     */
    private function calendar(stdClass $row, array $calendarPaths): array
    {
        if ($calendarPaths[(int) $row->id] ?? false) {
            return [true, CalendarSignal::Path];
        }

        $origin = DiscoveryOrigin::from((string) $row->origin);
        $embedded = $origin === DiscoveryOrigin::Iframe || $origin === DiscoveryOrigin::Embed;
        $parent = $row->parent_id === null ? null : (int) $row->parent_id;

        if ($embedded && $parent !== null && ($calendarPaths[$parent] ?? false)) {
            return [true, CalendarSignal::EmbeddedInCalendar];
        }

        return [false, null];
    }

    private function previousObservation(WebSource $source, string $canonicalUrl): ?Observation
    {
        return $this->observations->find($source->reference(), $canonicalUrl);
    }

    private function bounds(WebSource $source, IngestionRun $run): InventoryBounds
    {
        $row = $this->connection->table('aleph_ingestion_runs')->where('id', $run->id)->first();
        $stats = $row?->stats === null ? [] : json_decode((string) $row->stats, true, 512, JSON_THROW_ON_ERROR);
        $status = $row?->status === null ? $run->status : RunStatus::from((string) $row->status);

        return new InventoryBounds(
            runId: $run->id,
            sourceReference: $run->sourceReference,
            sourceName: $source->name,
            capability: $run->capability,
            status: $status,
            startedAt: $run->startedAt,
            finishedAt: $this->moment($row?->finished_at),
            maxPages: $source->limits->maxPages,
            maxDepth: $source->limits->maxDepth,
            seeds: $source->seeds,
            allowedHosts: $source->hosts->allowed(),
            hostRestrictions: $source->hosts->restrictions(),
            excluded: $source->excluded,
            queryParameters: $source->allowedQueryParameters,
            calendarSignals: $source->calendarSignals,
            stats: is_array($stats) ? $stats : [],
            error: $row?->error === null ? null : (string) $row->error,
        );
    }

    private function moment(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_frontier_candidates as candidates');
    }
}
