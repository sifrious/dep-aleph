<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\ScoreTab;

use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Value\ExtractionDraft;
use Sifrious\Funes\Value\IngestionRun as FunesIngestionRun;
use Sifrious\Funes\Value\Producer;
use Sifrious\Funes\Value\ProducerContext;

/**
 * Stores optional local-model derivations as versioned Funes extracted representations.
 * Does not overwrite raw score/tab bytes and does not invent music-domain columns.
 */
final readonly class FunesScoreTabDerivationRecorder implements ScoreTabDerivationRecorder
{
    public function __construct(private ObservationStore $observations) {}

    public function record(string $observationId, ScoreTabModelDerivation $derivation, string $runId): void
    {
        $this->observations->recordExtraction(new ExtractionDraft(
            observationId: $observationId,
            extractor: $derivation->modelName,
            version: $derivation->modelVersion,
            producerContext: new ProducerContext(
                new Producer(
                    'aleph:score-tab/local-model/'.$derivation->modelName,
                    'Aleph score/tab local model '.$derivation->modelName,
                ),
                new FunesIngestionRun($runId),
            ),
            result: [
                'kind' => 'score_tab_derived_representation',
                'model' => [
                    'name' => $derivation->modelName,
                    'version' => $derivation->modelVersion,
                ],
                // Opaque payload: music document graph schema is Funes MME-2202.
                'representation' => $derivation->representation,
            ],
        ));
    }
}
