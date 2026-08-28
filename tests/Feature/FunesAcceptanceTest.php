<?php

declare(strict_types=1);

use Sifrious\Aleph\Acceptance\AcceptanceClient;
use Sifrious\Aleph\Acceptance\IdempotencyKey;
use Sifrious\Aleph\Acceptance\Submissions;
use Sifrious\Aleph\Acceptance\SubmissionStatus;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Normalization\CandidateEnvelope;
use Sifrious\Aleph\Normalization\CandidateEnvelopes;
use Sifrious\Aleph\Normalization\NormalizationInput;
use Sifrious\Aleph\Normalization\NormalizationRunner;
use Sifrious\Aleph\Normalization\Reference\ShellCommandNormalizer;
use Sifrious\Funes\Acceptance\AcceptanceGateway;

function candidateFor(string $payload = 'git push origin main')
{
    $input = NormalizationInput::for(
        'shell:host/laptop',
        'shell:command/'.substr(hash('sha256', $payload), 0, 8),
        $payload,
        new Provenance('pigeon-post', '0.3.1', 'inst-1', new DateTimeImmutable('2026-08-27T09:05:00+00:00'), 'run-7'),
    );

    return app(NormalizationRunner::class)
        ->run(new ShellCommandNormalizer, $input, useCache: false)
        ->candidates
        ->first();
}

it('accepts a candidate and returns an authoritative Funes id', function (): void {
    $record = app(AcceptanceClient::class)->submit(candidateFor(), 'attempt-1');

    expect($record->submission->status)->toBe(SubmissionStatus::Accepted)
        ->and($record->acceptedId())->not->toBeNull()
        ->and($record->submission->acceptedType)->toBe('observation')
        ->and(DB::table('funes_observations')->where('id', $record->acceptedId())->exists())->toBeTrue();
});

it('never invents a Funes id of its own', function (): void {
    $record = app(AcceptanceClient::class)->submit(candidateFor());

    expect($record->acceptedId())->toBe(DB::table('funes_observations')->value('id'));
});

it('produces one accepted fact when the same candidate is submitted ten times', function (): void {
    $client = app(AcceptanceClient::class);

    for ($i = 0; $i < 10; $i++) {
        $records[] = $client->submit(candidateFor());
    }

    $ids = array_unique(array_map(static fn ($r): string => $r->acceptedId(), $records));

    expect($ids)->toHaveCount(1)
        ->and(DB::table('funes_observations')->count())->toBe(1)
        ->and(DB::table('aleph_funes_submissions')->count())->toBe(10)
        ->and(DB::table('aleph_funes_submissions')->where('status', 'accepted')->count())->toBe(1)
        ->and(DB::table('aleph_funes_submissions')->where('status', 'replayed')->count())->toBe(9);
});

it('derives the idempotency key from submission identity, not a random uuid', function (): void {
    $a = IdempotencyKey::for(candidateFor('ls -la'));
    $b = IdempotencyKey::for(candidateFor('ls -la'));
    $c = IdempotencyKey::for(candidateFor('ls -l'));

    expect((string) $a)->toBe((string) $b)
        ->and((string) $a)->not->toBe((string) $c)
        ->and((string) $a)->toStartWith('v1:');
});

it('changes the key when the normalizer version changes', function (): void {
    $candidate = candidateFor('pwd');
    $original = (string) IdempotencyKey::for($candidate);

    $bumped = new CandidateEnvelope(
        $candidate->schema,
        $candidate->normalizer->withVersion(99),
        $candidate->raw,
        $candidate->envelope,
    );

    expect((string) IdempotencyKey::for($bumped))->not->toBe($original);
});

it('records a transport failure as retryable rather than rejected', function (): void {
    $gateway = $this->mock(AcceptanceGateway::class);
    $gateway->shouldReceive('accept')->andThrow(new RuntimeException('connection reset'));

    $record = app(AcceptanceClient::class)->submit(candidateFor());

    expect($record->submission->status)->toBe(SubmissionStatus::TransportFailed)
        ->and($record->submission->status->shouldRetry())->toBeTrue()
        ->and($record->isAuthoritative())->toBeFalse()
        ->and($record->submission->error)->toContain('connection reset')
        ->and(DB::table('funes_observations')->count())->toBe(0);
});

it('separates validation rejection from transport failure', function (): void {
    expect(SubmissionStatus::Rejected->shouldRetry())->toBeFalse()
        ->and(SubmissionStatus::TransportFailed->shouldRetry())->toBeTrue()
        ->and(SubmissionStatus::Rejected->isAuthoritative())->toBeFalse();
});

it('makes a retry after an uncertain failure safe', function (): void {
    $client = app(AcceptanceClient::class);
    $first = $client->submit(candidateFor());
    $retry = $client->submit(candidateFor());

    expect($retry->submission->status)->toBe(SubmissionStatus::Replayed)
        ->and($retry->acceptedId())->toBe($first->acceptedId())
        ->and(DB::table('funes_observations')->count())->toBe(1);
});

it('keeps the full lineage from raw evidence to accepted id', function (): void {
    $payload = 'terraform plan';
    $record = app(AcceptanceClient::class)->submit(candidateFor($payload), 'attempt-9');

    $metadata = json_decode(
        DB::table('funes_observations')->where('id', $record->acceptedId())->value('metadata'),
        true,
    );

    expect($metadata['aleph']['normalization']['raw']['input_hash'])->toBe(hash('sha256', $payload))
        ->and($metadata['aleph']['normalization']['normalizer'])->toBe('shell-command@3')
        ->and($metadata['aleph']['normalization']['candidate_schema'])->toBe('activity.command@2')
        ->and($metadata['aleph']['provenance']['connector'])->toBe('pigeon-post')
        ->and($record->submission->attemptId)->toBe('attempt-9');
});

it('can navigate from an accepted id back to its submission', function (): void {
    $record = app(AcceptanceClient::class)->submit(candidateFor());

    $found = app(Submissions::class)->forKey($record->submission->idempotencyKey);

    expect($found)->toHaveCount(1)
        ->and($found[0]->acceptedId)->toBe($record->acceptedId())
        ->and($found[0]->payloadHash)->toBe($record->submission->payloadHash);
});

it('lists retryable submissions without listing settled ones', function (): void {
    $client = app(AcceptanceClient::class);
    $client->submit(candidateFor('ls'));

    app(Submissions::class)->open('key-stranded', str_repeat('a', 64), null);

    $retryable = app(Submissions::class)->retryable();

    expect($retryable)->toHaveCount(1)
        ->and($retryable[0]->idempotencyKey)->toBe('key-stranded');
});

function impossibleCandidate(string $payload = 'occurred after it was seen'): CandidateEnvelope
{
    return CandidateEnvelope::direct(new ObservationEnvelope(
        sourceReference: 'shell:host/laptop',
        sourceName: 'Laptop',
        resourceReference: 'shell:command/'.substr(hash('sha256', $payload), 0, 8),
        observedAt: new DateTimeImmutable('2026-08-27T09:00:00+00:00'),
        payload: $payload,
        provenance: new Provenance('pigeon-post', '0.3.1', 'inst-1', new DateTimeImmutable('2026-08-27T09:05:00+00:00')),
        occurredAt: new DateTimeImmutable('2026-08-27T10:00:00+00:00'),
    ));
}

it('settles each candidate in a batch independently', function (): void {
    $records = app(AcceptanceClient::class)->submitAll(
        new CandidateEnvelopes(candidateFor('one'), impossibleCandidate(), candidateFor('three')),
        'attempt-batch',
    );

    expect(array_map(static fn ($r): string => $r->submission->status->value, $records))
        ->toBe(['accepted', 'rejected', 'accepted'])
        ->and(DB::table('funes_observations')->count())->toBe(2);
});

it('does not let one rejection discard the rest of a batch', function (): void {
    app(AcceptanceClient::class)->submitAll(
        new CandidateEnvelopes(impossibleCandidate(), candidateFor('survivor')),
    );

    expect(DB::table('funes_observations')->count())->toBe(1)
        ->and(DB::table('aleph_funes_submissions')->where('status', 'accepted')->count())->toBe(1)
        ->and(DB::table('aleph_funes_submissions')->where('status', 'rejected')->count())->toBe(1);
});

it('reserves no idempotency key for a rejected candidate', function (): void {
    app(AcceptanceClient::class)->submit(impossibleCandidate());

    expect(DB::table('funes_idempotency_keys')->count())->toBe(0);
});

it('reports in flight rather than accepted when another submitter holds the key', function (): void {
    $candidate = candidateFor('contended');

    DB::table('funes_idempotency_keys')->insert([
        'key' => (string) IdempotencyKey::for($candidate),
        'reserved_at' => new DateTimeImmutable,
    ]);

    $record = app(AcceptanceClient::class)->submit($candidate);

    expect($record->submission->status)->toBe(SubmissionStatus::InFlight)
        ->and($record->isAuthoritative())->toBeFalse()
        ->and($record->submission->status->shouldRetry())->toBeTrue()
        ->and(DB::table('funes_observations')->count())->toBe(0);
});

it('produces one accepted fact when a contended key is later resolved and retried', function (): void {
    $candidate = candidateFor('contended');
    $key = (string) IdempotencyKey::for($candidate);

    DB::table('funes_idempotency_keys')->insert(['key' => $key, 'reserved_at' => new DateTimeImmutable]);
    app(AcceptanceClient::class)->submit($candidate);

    DB::table('funes_idempotency_keys')->where('key', $key)->delete();
    $winner = app(AcceptanceClient::class)->submit($candidate);
    $loser = app(AcceptanceClient::class)->submit($candidate);

    expect($winner->submission->status)->toBe(SubmissionStatus::Accepted)
        ->and($loser->submission->status)->toBe(SubmissionStatus::Replayed)
        ->and($loser->acceptedId())->toBe($winner->acceptedId())
        ->and(DB::table('funes_observations')->count())->toBe(1);
});

it('gives a connector no way to create history except a successful acceptance', function (): void {
    $envelope = new ObservationEnvelope(
        sourceReference: 'pigeon:loft/ashcroft',
        sourceName: 'Ashcroft Loft',
        resourceReference: 'pigeon:ring/AC-1',
        observedAt: new DateTimeImmutable('2026-08-27T09:00:00+00:00'),
        payload: 'wind fair, weight 412g',
        provenance: new Provenance('pigeon-post', '0.3.1', 'inst-1', new DateTimeImmutable('2026-08-27T09:05:00+00:00')),
    );

    $record = app(EnvelopeSubmitter::class)->submit($envelope);

    expect($record->isAuthoritative())->toBeTrue()
        ->and(DB::table('funes_observations')->value('id'))->toBe($record->acceptedId())
        ->and(DB::table('aleph_funes_submissions')->where('accepted_id', $record->acceptedId())->exists())
        ->toBeTrue()
        ->and(DB::table('funes_idempotency_keys')->where('accepted_id', $record->acceptedId())->exists())
        ->toBeTrue();
});

it('keeps a directly submitted envelope traceable to its own raw evidence', function (): void {
    $payload = 'wind fair, weight 412g';

    app(EnvelopeSubmitter::class)->submit(new ObservationEnvelope(
        sourceReference: 'pigeon:loft/ashcroft',
        sourceName: 'Ashcroft Loft',
        resourceReference: 'pigeon:ring/AC-1',
        observedAt: new DateTimeImmutable('2026-08-27T09:00:00+00:00'),
        payload: $payload,
        provenance: new Provenance('pigeon-post', '0.3.1', 'inst-1', new DateTimeImmutable('2026-08-27T09:05:00+00:00')),
    ));

    $normalization = json_decode((string) DB::table('funes_observations')->value('metadata'), true)['aleph']['normalization'];

    expect($normalization['normalizer'])->toBe('aleph.direct@1')
        ->and($normalization['candidate_schema'])->toBe('aleph.envelope@1')
        ->and($normalization['raw']['input_hash'])->toBe(hash('sha256', $payload));
});
