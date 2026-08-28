<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Acceptance;

use Sifrious\Aleph\Normalization\CandidateEnvelope;

final readonly class IdempotencyKey
{
    public const VERSION = 1;

    private function __construct(public string $value) {}

    public static function for(CandidateEnvelope $candidate): self
    {
        $envelope = $candidate->envelope;

        return new self('v'.self::VERSION.':'.hash('sha256', implode("\n", [
            $envelope->sourceReference,
            $envelope->account ?? '',
            $envelope->stream ?? '',
            $envelope->resourceReference,
            $envelope->providerId ?? '',
            $envelope->providerRevision ?? '',
            $candidate->schema->reference(),
            $candidate->normalizer->reference(),
            $candidate->raw->inputHash,
        ])));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
