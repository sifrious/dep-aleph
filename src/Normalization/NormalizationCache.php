<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization;

use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

final readonly class NormalizationCache
{
    public function __construct(
        private Cache $cache,
        private int $ttl = 604800,
    ) {}

    public function key(NormalizationInput $input, Normalizer $normalizer): string
    {
        return 'aleph:normalization:'.hash('sha256', implode('|', [
            $input->inputHash(),
            $normalizer->identity()->id,
            (string) $normalizer->identity()->version,
            $normalizer->schema()->reference(),
            $input->contextVersion ?? '',
        ]));
    }

    public function get(NormalizationInput $input, Normalizer $normalizer): ?CandidateEnvelopes
    {
        $stored = $this->cache->get($this->key($input, $normalizer));

        if (! is_string($stored)) {
            return null;
        }

        try {
            $candidates = unserialize($stored);
        } catch (Throwable) {
            return null;
        }

        return $candidates instanceof CandidateEnvelopes ? $candidates : null;
    }

    public function put(NormalizationInput $input, Normalizer $normalizer, CandidateEnvelopes $candidates): void
    {
        $this->cache->put($this->key($input, $normalizer), serialize($candidates), $this->ttl);
    }

    public function forget(NormalizationInput $input, Normalizer $normalizer): void
    {
        $this->cache->forget($this->key($input, $normalizer));
    }
}
