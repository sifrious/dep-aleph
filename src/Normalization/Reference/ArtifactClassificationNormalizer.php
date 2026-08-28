<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization\Reference;

use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Normalization\CandidateEnvelope;
use Sifrious\Aleph\Normalization\CandidateEnvelopes;
use Sifrious\Aleph\Normalization\CandidateSchema;
use Sifrious\Aleph\Normalization\NormalizationInput;
use Sifrious\Aleph\Normalization\Normalizer;
use Sifrious\Aleph\Normalization\NormalizerIdentity;

final readonly class ArtifactClassificationNormalizer implements Normalizer
{
    private const CATEGORIES = [
        'md' => 'document',
        'markdown' => 'document',
        'pdf' => 'document',
        'png' => 'image',
        'jpg' => 'image',
        'jpeg' => 'image',
        'csv' => 'dataset',
        'json' => 'dataset',
    ];

    public function identity(): NormalizerIdentity
    {
        return new NormalizerIdentity('artifact-classification', 1);
    }

    public function schema(): CandidateSchema
    {
        return new CandidateSchema('artifact.classified', 1);
    }

    public function supports(NormalizationInput $input): bool
    {
        return $input->contextValue('path') !== null;
    }

    public function normalize(NormalizationInput $input): CandidateEnvelopes
    {
        $path = (string) $input->contextValue('path', '');
        $category = self::CATEGORIES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        if ($category === null) {
            return CandidateEnvelopes::none();
        }

        $envelope = new ObservationEnvelope(
            sourceReference: $input->raw->sourceReference,
            sourceName: $input->raw->sourceReference,
            resourceReference: $input->raw->resourceReference,
            observedAt: $input->provenance->capturedAt,
            payload: $input->payload,
            provenance: $input->provenance,
            contentType: $input->contentType() ?? 'application/octet-stream',
            eventType: 'artifact.classified',
            extensions: [
                new ExtensionMetadata('artifact.classification', 1, [
                    'path' => $path,
                    'category' => $category,
                    'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                    'byte_size' => strlen($input->payload),
                ]),
            ],
        );

        return new CandidateEnvelopes(
            new CandidateEnvelope($this->schema(), $this->identity(), $input->raw, $envelope),
        );
    }
}
