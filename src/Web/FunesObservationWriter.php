<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Sifrious\Aleph\Extraction\MechanicalExtraction;
use Sifrious\Aleph\Ingestion\IngestionRun;
use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Value\Discovery;
use Sifrious\Funes\Value\ExtractionDraft;
use Sifrious\Funes\Value\ObservationDraft;

final readonly class FunesObservationWriter
{
    public function __construct(private ObservationStore $observations) {}

    /**
     * @param  list<CanonicalDiscovery>  $discoveries
     */
    public function accept(
        WebSource $source,
        IngestionRun $run,
        FrontierCandidate $candidate,
        CanonicalUrl $resource,
        FetchResult $result,
        MechanicalExtraction $extraction,
        array $discoveries,
    ): AcceptedRetrieval {
        $accepted = $this->observations->accept(new ObservationDraft(
            sourceReference: $source->reference(),
            sourceName: $source->name,
            resourceReference: $resource->value,
            observedAt: $result->retrievedAt,
            payload: $result->body ?? '',
            contentType: $result->contentType ?? 'application/octet-stream',
            metadata: [
                'http_status' => $result->status,
                'requested_url' => $result->requestedUrl,
                'final_url' => $result->finalUrl,
                'redirect_chain' => $result->redirectChain,
                'ingestion_run_id' => $run->id,
                'discovery_origin' => $candidate->origin->value,
            ],
            discoveries: array_map(
                fn (CanonicalDiscovery $discovery): Discovery => new Discovery(
                    $discovery->url->value,
                    $discovery->relationship->value,
                ),
                $discoveries,
            ),
        ));

        return AcceptedRetrieval::of($accepted, $this->observations->recordExtraction(new ExtractionDraft(
            observationId: $accepted->observation->id,
            extractor: $extraction->extractor,
            version: $extraction->version,
            result: $extraction->result,
            failure: $extraction->failure,
        )));
    }
}
