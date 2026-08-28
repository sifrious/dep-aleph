<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Tests\Fixtures;

use RuntimeException;
use Sifrious\Funes\Association\EntityAssociation;
use Sifrious\Funes\Persistence\ObservationStore;
use Sifrious\Funes\Reference\CrossPackageReference;
use Sifrious\Funes\Relationship\HistoricalRelationship;
use Sifrious\Funes\Value\AcceptedObservation;
use Sifrious\Funes\Value\DiscoveryProvenance;
use Sifrious\Funes\Value\ExtractionDraft;
use Sifrious\Funes\Value\ExtractionResult;
use Sifrious\Funes\Value\Observation;
use Sifrious\Funes\Value\ObservationDraft;

final class FailingObservationStore implements ObservationStore
{
    public function __construct(
        private readonly ObservationStore $store,
        private int $failuresRemaining = 1,
    ) {}

    public function accept(ObservationDraft $draft): AcceptedObservation
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new RuntimeException('Funes acceptance failed.');
        }

        return $this->store->accept($draft);
    }

    public function find(string $sourceReference, string $resourceReference): ?Observation
    {
        return $this->store->find($sourceReference, $resourceReference);
    }

    public function get(string $observationId): ?Observation
    {
        return $this->store->get($observationId);
    }

    /**
     * @return list<EntityAssociation>
     */
    public function associationsTo(CrossPackageReference $entity): array
    {
        return $this->store->associationsTo($entity);
    }

    /**
     * @return list<HistoricalRelationship>
     */
    public function relationshipsTo(CrossPackageReference $event): array
    {
        return $this->store->relationshipsTo($event);
    }

    /**
     * @return list<DiscoveryProvenance>
     */
    public function discoveriesTo(string $sourceReference, string $resourceReference): array
    {
        return $this->store->discoveriesTo($sourceReference, $resourceReference);
    }

    public function recordExtraction(ExtractionDraft $draft): ExtractionResult
    {
        return $this->store->recordExtraction($draft);
    }
}
