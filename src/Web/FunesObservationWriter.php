<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Sifrious\Aleph\Ingestion\IngestionRun;
use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Value\AcceptedObservation;
use Sifrious\Funes\Value\Discovery;
use Sifrious\Funes\Value\ObservationDraft;

final readonly class FunesObservationWriter
{
    public function __construct(private ObservationStore $observations) {}

    /**
     * @param  list<CanonicalUrl>  $discoveries
     */
    public function accept(
        WebSource $source,
        IngestionRun $run,
        FrontierCandidate $candidate,
        CanonicalUrl $resource,
        FetchResult $result,
        array $discoveries,
    ): AcceptedObservation {
        return $this->observations->accept(new ObservationDraft(
            sourceReference: "web:{$source->key}",
            sourceName: $source->name,
            resourceReference: $resource->value,
            observedAt: $result->retrievedAt,
            payload: $result->body ?? '',
            mediaType: $result->contentType ?? 'application/octet-stream',
            metadata: [
                'http_status' => $result->status,
                'requested_url' => $result->requestedUrl,
                'final_url' => $result->finalUrl,
                'redirect_chain' => $result->redirectChain,
                'ingestion_run_id' => $run->id,
                'discovery_origin' => $candidate->origin->value,
            ],
            discoveries: array_map(
                fn (CanonicalUrl $url): Discovery => new Discovery($url->value, 'link'),
                $discoveries,
            ),
        ));
    }
}
