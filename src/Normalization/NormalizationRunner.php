<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use DateTimeImmutable;
use Throwable;

final readonly class NormalizationRunner
{
    public function __construct(
        private NormalizationAttempts $attempts,
        private CandidateValidator $validator,
        private ?NormalizationCache $cache = null,
    ) {}

    public function run(Normalizer $normalizer, NormalizationInput $input, bool $useCache = true): NormalizationResult
    {
        $startedAt = new DateTimeImmutable;
        $start = hrtime(true);

        if (! $normalizer->supports($input)) {
            return $this->finish(
                $normalizer,
                $input,
                CandidateEnvelopes::none(),
                NormalizationStatus::Unsupported,
                $startedAt,
                $start,
                false,
                'unsupported_input',
                'Normalizer does not support this input.',
            );
        }

        $cached = $useCache ? $this->cache?->get($input, $normalizer) : null;

        if ($cached instanceof CandidateEnvelopes) {
            return $this->finish(
                $normalizer,
                $input,
                $cached,
                $cached->isEmpty() ? NormalizationStatus::Empty : NormalizationStatus::Succeeded,
                $startedAt,
                $start,
                true,
            );
        }

        try {
            $candidates = $normalizer->normalize($input);
        } catch (MalformedInput $malformed) {
            return $this->finish(
                $normalizer,
                $input,
                CandidateEnvelopes::none(),
                NormalizationStatus::Malformed,
                $startedAt,
                $start,
                false,
                'malformed_input',
                $malformed->getMessage(),
            );
        } catch (Throwable $failure) {
            return $this->finish(
                $normalizer,
                $input,
                CandidateEnvelopes::none(),
                NormalizationStatus::Failed,
                $startedAt,
                $start,
                false,
                'normalizer_error',
                $failure::class.': '.$failure->getMessage(),
            );
        }

        $violations = $this->validator->violationsFor($candidates, $input);

        if ($violations !== []) {
            return $this->finish(
                $normalizer,
                $input,
                CandidateEnvelopes::none(),
                NormalizationStatus::Invalid,
                $startedAt,
                $start,
                false,
                'candidate_invalid',
                implode('; ', $violations),
                $violations,
            );
        }

        if ($useCache) {
            $this->cache?->put($input, $normalizer, $candidates);
        }

        return $this->finish(
            $normalizer,
            $input,
            $candidates,
            $candidates->isEmpty() ? NormalizationStatus::Empty : NormalizationStatus::Succeeded,
            $startedAt,
            $start,
            false,
        );
    }

    /**
     * @param  list<string>  $violations
     */
    private function finish(
        Normalizer $normalizer,
        NormalizationInput $input,
        CandidateEnvelopes $candidates,
        NormalizationStatus $status,
        DateTimeImmutable $startedAt,
        float $start,
        bool $cached,
        ?string $errorCode = null,
        ?string $error = null,
        array $violations = [],
    ): NormalizationResult {
        $attempt = $this->attempts->record(new NormalizationAttempt(
            id: $this->attempts->newId(),
            ingestionAttemptId: $input->ingestionAttemptId,
            normalizer: $normalizer->identity(),
            schema: $normalizer->schema(),
            inputHash: $input->inputHash(),
            sourceReference: $input->raw->sourceReference,
            status: $status,
            candidateCount: $candidates->count(),
            cached: $cached,
            errorCode: $errorCode,
            error: $error,
            startedAt: $startedAt,
            completedAt: new DateTimeImmutable,
            durationMs: (int) round((hrtime(true) - $start) / 1_000_000),
        ));

        return new NormalizationResult($status, $candidates, $attempt, $violations);
    }
}
