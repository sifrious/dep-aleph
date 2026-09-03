<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Image;

use InvalidArgumentException;

/**
 * Outsourced classification result. Aleph does not classify images; it only stores what returns.
 */
final readonly class ImageClassificationObservation
{
    /**
     * @param  array<string, mixed>  $labels
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public string $observationId,
        public string $classifierName,
        public string $classifierVersion,
        public array $labels,
        public string $runId,
        public array $provenance = [],
    ) {
        if (trim($observationId) === '') {
            throw new InvalidArgumentException('An image classification observation requires an observation id.');
        }

        if (trim($classifierName) === '' || trim($classifierVersion) === '') {
            throw new InvalidArgumentException('An image classification observation requires classifier name and version.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toExtractionResult(): array
    {
        return [
            'kind' => 'image_classification_observation',
            'classifier' => [
                'name' => $this->classifierName,
                'version' => $this->classifierVersion,
            ],
            'labels' => $this->labels,
            'provenance' => $this->provenance,
        ];
    }
}
