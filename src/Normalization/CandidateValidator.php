<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use Sifrious\Aleph\Envelope\ExtensionMetadata;

final class CandidateValidator
{
    /**
     * @return list<string>
     */
    public function violations(CandidateEnvelope $candidate, NormalizationInput $input): array
    {
        $violations = [];
        $envelope = $candidate->envelope;

        if ($candidate->raw->inputHash !== $input->inputHash()) {
            $violations[] = 'candidate does not reference the raw evidence it was normalized from';
        }

        if ($envelope->sourceReference !== $input->raw->sourceReference) {
            $violations[] = 'candidate source identity does not match the normalization input';
        }

        if ($envelope->resourceReference === '') {
            $violations[] = 'candidate must name a resource';
        }

        if ($envelope->provenance->connectorId !== $input->provenance->connectorId) {
            $violations[] = 'candidate provenance does not preserve the connector that captured the evidence';
        }

        if ($envelope->provenance->installationId !== $input->provenance->installationId) {
            $violations[] = 'candidate provenance does not preserve the installation';
        }

        foreach ($envelope->extensions as $extension) {
            if (! $extension instanceof ExtensionMetadata) {
                $violations[] = 'candidate carries a malformed extension';
            }
        }

        $lineage = $candidate->toObservationEnvelope()->metadata()['aleph']['normalization'] ?? null;

        if (! is_array($lineage) || ($lineage['normalizer'] ?? null) === null) {
            $violations[] = 'candidate does not record the normalizer that produced it';
        }

        if (! is_array($lineage) || ($lineage['candidate_schema'] ?? null) === null) {
            $violations[] = 'candidate does not record its schema version';
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public function violationsFor(CandidateEnvelopes $candidates, NormalizationInput $input): array
    {
        $violations = [];

        foreach ($candidates as $index => $candidate) {
            foreach ($this->violations($candidate, $input) as $violation) {
                $violations[] = "candidate {$index}: {$violation}";
            }
        }

        return $violations;
    }
}
