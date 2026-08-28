<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use RuntimeException;
use Sifrious\Aleph\Acceptance\AcceptanceClient;
use Sifrious\Aleph\Envelope\DiscoveryReference;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Extraction\MechanicalExtraction;
use Sifrious\Aleph\Ingestion\IngestionRun;
use Sifrious\Aleph\Normalization\NormalizationInput;
use Sifrious\Aleph\Normalization\NormalizationRunner;
use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Value\ExtractionDraft;

final readonly class FunesObservationWriter
{
    public function __construct(
        private ObservationStore $observations,
        private NormalizationRunner $normalization,
        private AcceptanceClient $acceptance,
        private WebRetrievalNormalizer $normalizer,
        private string $connectorVersion = '1.0.0',
    ) {}

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
        $normalized = $this->normalization->run($this->normalizer, $this->inputFor(
            $source,
            $run,
            $candidate,
            $resource,
            $result,
            $discoveries,
        ));

        $envelope = $normalized->candidates->first();

        if ($envelope === null) {
            throw new RuntimeException(
                "Web retrieval for [{$resource->value}] produced no candidate: {$normalized->status->value}."
            );
        }

        $record = $this->acceptance->submit($envelope, $run->id);

        if (! $record->isAuthoritative()) {
            throw new RuntimeException(sprintf(
                'Funes did not accept [%s] (%s): %s',
                $resource->value,
                $record->submission->status->value,
                $record->submission->error ?? 'no reason reported',
            ));
        }

        $observation = $this->observations->get((string) $record->acceptedId());

        if ($observation === null) {
            throw new RuntimeException('Funes reported an accepted id that cannot be read back.');
        }

        return AcceptedRetrieval::fromAccepted(
            $observation,
            $record->disposition,
            $record->submission->status,
            $this->observations->recordExtraction(new ExtractionDraft(
                observationId: $observation->id,
                extractor: $extraction->extractor,
                version: $extraction->version,
                result: $extraction->result,
                failure: $extraction->failure,
            )),
        );
    }

    /**
     * @param  list<CanonicalDiscovery>  $discoveries
     */
    private function inputFor(
        WebSource $source,
        IngestionRun $run,
        FrontierCandidate $candidate,
        CanonicalUrl $resource,
        FetchResult $result,
        array $discoveries,
    ): NormalizationInput {
        return NormalizationInput::for(
            sourceReference: $source->reference(),
            resourceReference: $resource->value,
            payload: $result->body ?? '',
            provenance: new Provenance(
                connectorId: 'web-crawl',
                connectorVersion: $this->connectorVersion,
                installationId: $source->reference(),
                capturedAt: $result->retrievedAt,
                runId: $run->id,
            ),
            contentType: $result->contentType ?? 'application/octet-stream',
            context: [
                'source_name' => $source->name,
                'http_status' => $result->status,
                'requested_url' => $result->requestedUrl,
                'final_url' => $result->finalUrl,
                'redirect_chain' => $result->redirectChain,
                'ingestion_run_id' => $run->id,
                'discovery_origin' => $candidate->origin->value,
                'discoveries' => array_map(
                    static fn (CanonicalDiscovery $discovery): DiscoveryReference => new DiscoveryReference(
                        $discovery->url->value,
                        $discovery->relationship->value,
                    ),
                    $discoveries,
                ),
            ],
            ingestionAttemptId: $run->id,
        );
    }
}
