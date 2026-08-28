<?php

declare(strict_types=1);

use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Normalization\CandidateEnvelope;
use Sifrious\Aleph\Normalization\CandidateEnvelopes;
use Sifrious\Aleph\Normalization\CandidateSchema;
use Sifrious\Aleph\Normalization\NormalizationAttempts;
use Sifrious\Aleph\Normalization\NormalizationCache;
use Sifrious\Aleph\Normalization\NormalizationInput;
use Sifrious\Aleph\Normalization\NormalizationRunner;
use Sifrious\Aleph\Normalization\NormalizationStatus;
use Sifrious\Aleph\Normalization\Normalizer;
use Sifrious\Aleph\Normalization\NormalizerIdentity;
use Sifrious\Aleph\Normalization\RawReference;

function countingNormalizer(int $version = 1): Normalizer
{
    return new class($version) implements Normalizer
    {
        public int $calls = 0;

        public function __construct(private readonly int $version) {}

        public function identity(): NormalizerIdentity
        {
            return new NormalizerIdentity('counting', $this->version);
        }

        public function schema(): CandidateSchema
        {
            return new CandidateSchema('test.counted', 1);
        }

        public function supports(NormalizationInput $input): bool
        {
            return true;
        }

        public function normalize(NormalizationInput $input): CandidateEnvelopes
        {
            $this->calls++;

            return new CandidateEnvelopes(new CandidateEnvelope(
                $this->schema(),
                $this->identity(),
                $input->raw,
                new ObservationEnvelope(
                    sourceReference: $input->raw->sourceReference,
                    sourceName: 'Counting',
                    resourceReference: $input->raw->resourceReference,
                    observedAt: $input->provenance->capturedAt,
                    payload: $input->payload,
                    provenance: $input->provenance,
                    eventType: 'counted.v'.$this->version,
                ),
            ));
        }
    };
}

function cacheInput(string $payload = 'stable evidence'): NormalizationInput
{
    return NormalizationInput::for(
        'test:source',
        'test:resource',
        $payload,
        new Provenance('c', '1.0.0', 'inst', new DateTimeImmutable('2026-08-27T09:00:00+00:00')),
    );
}

it('reuses a cached result for identical input and normalizer version', function (): void {
    $normalizer = countingNormalizer();
    $runner = app(NormalizationRunner::class);

    $first = $runner->run($normalizer, cacheInput());
    $second = $runner->run($normalizer, cacheInput());

    expect($normalizer->calls)->toBe(1)
        ->and($first->attempt->cached)->toBeFalse()
        ->and($second->attempt->cached)->toBeTrue()
        ->and($second->candidates)->toHaveCount(1);
});

it('records an attempt even when the result was served from cache', function (): void {
    $normalizer = countingNormalizer();
    $runner = app(NormalizationRunner::class);

    $runner->run($normalizer, cacheInput());
    $runner->run($normalizer, cacheInput());

    expect(DB::table('aleph_normalization_attempts')->count())->toBe(2)
        ->and(DB::table('aleph_normalization_attempts')->where('cached', true)->count())->toBe(1);
});

it('misses the cache when the normalizer version changes', function (): void {
    $runner = app(NormalizationRunner::class);
    $v1 = countingNormalizer(1);
    $v2 = countingNormalizer(2);

    $runner->run($v1, cacheInput());
    $result = $runner->run($v2, cacheInput());

    expect($v2->calls)->toBe(1)
        ->and($result->attempt->cached)->toBeFalse()
        ->and($result->candidates->first()->envelope->eventType)->toBe('counted.v2');
});

it('misses the cache when the evidence changes', function (): void {
    $normalizer = countingNormalizer();
    $runner = app(NormalizationRunner::class);

    $runner->run($normalizer, cacheInput('evidence one'));
    $runner->run($normalizer, cacheInput('evidence two'));

    expect($normalizer->calls)->toBe(2);
});

it('keys the cache on evidence and normalizer version together', function (): void {
    $cache = app(NormalizationCache::class);

    $sameKey = $cache->key(cacheInput(), countingNormalizer(1)) === $cache->key(cacheInput(), countingNormalizer(1));
    $versionKey = $cache->key(cacheInput(), countingNormalizer(1)) === $cache->key(cacheInput(), countingNormalizer(2));
    $evidenceKey = $cache->key(cacheInput('a'), countingNormalizer(1)) === $cache->key(cacheInput('b'), countingNormalizer(1));

    expect($sameKey)->toBeTrue()->and($versionKey)->toBeFalse()->and($evidenceKey)->toBeFalse();
});

it('re-normalizes preserved evidence under a newer version without rewriting the old attempt', function (): void {
    $runner = app(NormalizationRunner::class);

    $old = $runner->run(countingNormalizer(1), cacheInput());
    $new = $runner->run(countingNormalizer(2), cacheInput());

    $rows = DB::table('aleph_normalization_attempts')->orderBy('started_at')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->normalizer_version)->toBe(1)
        ->and($rows[1]->normalizer_version)->toBe(2)
        ->and($old->attempt->id)->not->toBe($new->attempt->id)
        ->and(app(NormalizationAttempts::class)->find($old->attempt->id)->normalizer->version)
        ->toBe(1);
});

it('can bypass the cache to prove reproducibility', function (): void {
    $normalizer = countingNormalizer();
    $runner = app(NormalizationRunner::class);

    $runner->run($normalizer, cacheInput(), useCache: false);
    $runner->run($normalizer, cacheInput(), useCache: false);

    expect($normalizer->calls)->toBe(2);
});

it('rejects a candidate that does not reference the evidence it came from', function (): void {
    $liar = new class implements Normalizer
    {
        public function identity(): NormalizerIdentity
        {
            return new NormalizerIdentity('liar', 1);
        }

        public function schema(): CandidateSchema
        {
            return new CandidateSchema('test.liar', 1);
        }

        public function supports(NormalizationInput $input): bool
        {
            return true;
        }

        public function normalize(NormalizationInput $input): CandidateEnvelopes
        {
            return new CandidateEnvelopes(new CandidateEnvelope(
                $this->schema(),
                $this->identity(),
                RawReference::forPayload('other:source', 'other:resource', 'unrelated evidence'),
                new ObservationEnvelope(
                    sourceReference: 'other:source',
                    sourceName: 'Other',
                    resourceReference: 'other:resource',
                    observedAt: $input->provenance->capturedAt,
                    payload: 'x',
                    provenance: $input->provenance,
                ),
            ));
        }
    };

    $result = app(NormalizationRunner::class)->run($liar, cacheInput());

    expect($result->status)->toBe(NormalizationStatus::Invalid)
        ->and($result->candidates->isEmpty())->toBeTrue()
        ->and($result->violations)->not->toBeEmpty()
        ->and($result->attempt->errorCode)->toBe('candidate_invalid');
});

it('writes nothing to Funes by normalizing alone', function (): void {
    app(NormalizationRunner::class)->run(countingNormalizer(), cacheInput());

    expect(DB::table('funes_observations')->count())->toBe(0)
        ->and(DB::table('funes_sources')->count())->toBe(0);
});
