<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

interface Normalizer
{
    public function identity(): NormalizerIdentity;

    public function schema(): CandidateSchema;

    public function supports(NormalizationInput $input): bool;

    public function normalize(NormalizationInput $input): CandidateEnvelopes;
}
