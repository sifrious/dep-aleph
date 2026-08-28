<?php

declare(strict_types=1);

use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ObservationMetadata;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Normalization\NormalizationAttempts;
use Sifrious\Aleph\Normalization\NormalizationInput;
use Sifrious\Aleph\Normalization\NormalizationRunner;
use Sifrious\Aleph\Normalization\NormalizationStatus;
use Sifrious\Aleph\Normalization\RawReference;
use Sifrious\Aleph\Normalization\Reference\ArtifactClassificationNormalizer;
use Sifrious\Aleph\Normalization\Reference\ShellCommandNormalizer;
use Sifrious\Aleph\Normalization\Reference\TranscriptNormalizer;
use Sifrious\Funes\Persistence\ObservationStore;

function provenance(): Provenance
{
    return new Provenance(
        'pigeon-post',
        '0.3.1',
        'inst-1',
        new DateTimeImmutable('2026-08-27T09:05:00+00:00'),
        'run-7',
    );
}

function input(string $payload, array $context = [], ?string $contentType = null): NormalizationInput
{
    return NormalizationInput::for(
        sourceReference: 'shell:host/laptop',
        resourceReference: 'shell:command/'.substr(hash('sha256', $payload), 0, 8),
        payload: $payload,
        provenance: provenance(),
        contentType: $contentType,
        context: $context,
        ingestionAttemptId: 'attempt-1',
    );
}

function runner(): NormalizationRunner
{
    return app(NormalizationRunner::class);
}

it('turns one raw input into one candidate', function (): void {
    $result = runner()->run(new ShellCommandNormalizer, input('git status --short'));

    expect($result->status)->toBe(NormalizationStatus::Succeeded)
        ->and($result->candidates)->toHaveCount(1)
        ->and($result->candidates->first()->envelope->extension('shell.command')->data['binary'])->toBe('git');
});

it('turns one raw input into many candidates', function (): void {
    $payload = json_encode(['session_id' => 'S-1', 'messages' => [
        ['role' => 'human', 'text' => 'hello', 'at' => '2026-08-27T09:00:00+00:00'],
        ['role' => 'assistant', 'text' => 'hi', 'at' => '2026-08-27T09:00:05+00:00'],
        ['role' => 'system', 'text' => 'note'],
    ]], JSON_THROW_ON_ERROR);

    $result = runner()->run(new TranscriptNormalizer, input($payload, contentType: 'application/json'));

    expect($result->status)->toBe(NormalizationStatus::Succeeded)
        ->and($result->candidates)->toHaveCount(3)
        ->and($result->attempt->candidateCount)->toBe(3);
});

it('accepts valid input that legitimately yields zero candidates', function (): void {
    $result = runner()->run(new ArtifactClassificationNormalizer, input('binary', ['path' => 'notes.xyz']));

    expect($result->status)->toBe(NormalizationStatus::Empty)
        ->and($result->candidates->isEmpty())->toBeTrue()
        ->and($result->successful())->toBeTrue();
});

it('distinguishes malformed input from a normalizer bug', function (): void {
    $result = runner()->run(new TranscriptNormalizer, input('not json at all', contentType: 'application/json'));

    expect($result->status)->toBe(NormalizationStatus::Malformed)
        ->and($result->attempt->errorCode)->toBe('malformed_input')
        ->and($result->successful())->toBeFalse();
});

it('distinguishes unsupported input from failure', function (): void {
    $result = runner()->run(new ArtifactClassificationNormalizer, input('bytes'));

    expect($result->status)->toBe(NormalizationStatus::Unsupported)
        ->and($result->attempt->errorCode)->toBe('unsupported_input');
});

it('records an operational attempt for success and for failure', function (): void {
    runner()->run(new ShellCommandNormalizer, input('ls -la'));
    runner()->run(new TranscriptNormalizer, input('{', contentType: 'application/json'));

    $attempts = app(NormalizationAttempts::class);

    expect(DB::table('aleph_normalization_attempts')->count())->toBe(2)
        ->and($attempts->forNormalizer((new ShellCommandNormalizer)->identity())[0]->status)
        ->toBe(NormalizationStatus::Succeeded);
});

it('keeps every candidate pointing back at the exact raw evidence', function (): void {
    $payload = 'rsync -av /src /dst';
    $result = runner()->run(new ShellCommandNormalizer, input($payload));
    $candidate = $result->candidates->first();

    expect($candidate->raw->inputHash)->toBe(hash('sha256', $payload))
        ->and($candidate->raw->sourceReference)->toBe('shell:host/laptop');
});

it('preserves capture provenance from input through to candidate', function (): void {
    $candidate = runner()->run(new ShellCommandNormalizer, input('pwd'))->candidates->first();
    $aleph = $candidate->toObservationEnvelope()->metadata()['aleph'];

    expect($aleph['provenance'])->toMatchArray([
        'connector' => 'pigeon-post',
        'connector_version' => '0.3.1',
        'installation' => 'inst-1',
        'run' => 'run-7',
    ]);
});

it('names the raw input and exact normalizer version in the accepted record', function (): void {
    $payload = 'terraform apply';
    $candidate = runner()->run(new ShellCommandNormalizer, input($payload))->candidates->first();

    app(EnvelopeSubmitter::class)->submit($candidate->toObservationEnvelope());

    $observationId = (string) DB::table('funes_observations')->value('id');
    $normalization = ObservationMetadata::aleph(app(ObservationStore::class)->get($observationId))['normalization'];

    expect($normalization['normalizer'])->toBe('shell-command@3')
        ->and($normalization['normalizer_version'])->toBe(3)
        ->and($normalization['candidate_schema'])->toBe('activity.command@2')
        ->and($normalization['raw']['input_hash'])->toBe(hash('sha256', $payload))
        ->and($normalization['raw']['resource'])->toBe('shell:command/'.substr(hash('sha256', $payload), 0, 8));
});

it('replays deterministically for identical evidence', function (): void {
    $first = runner()->run(new ShellCommandNormalizer, input('docker ps -a'), useCache: false);
    $second = runner()->run(new ShellCommandNormalizer, input('docker ps -a'), useCache: false);

    expect($first->candidates->describe())->toBe($second->candidates->describe());
});

it('refuses an input whose payload has drifted from its hash', function (): void {
    $reference = RawReference::forPayload('s', 'r', 'original');

    new NormalizationInput($reference, 'tampered', provenance());
})->throws(InvalidArgumentException::class, 'diverged');
