<?php

declare(strict_types=1);

use Sifrious\Aleph\Normalization\CandidateSchema;
use Sifrious\Aleph\Normalization\NormalizerIdentity;
use Sifrious\Aleph\Normalization\RawReference;

it('gives a normalizer a durable identity independent of its class name', function (): void {
    $identity = new NormalizerIdentity('shell-command', 3);

    expect($identity->reference())->toBe('shell-command@3')
        ->and($identity->toArray())->toBe(['id' => 'shell-command', 'version' => 3]);
});

it('versions the candidate schema separately from the normalizer', function (): void {
    $normalizer = new NormalizerIdentity('transcript', 2);
    $schema = new CandidateSchema('communication.message', 3);

    expect($normalizer->reference())->toBe('transcript@2')
        ->and($schema->reference())->toBe('communication.message@3')
        ->and($normalizer->version)->not->toBe($schema->version);
});

it('lets a normalizer version change while the schema stays put', function (): void {
    $schema = new CandidateSchema('communication.message', 3);
    $v2 = new NormalizerIdentity('transcript', 2);
    $v3 = $v2->withVersion(3);

    expect($v3->reference())->toBe('transcript@3')
        ->and($v2->is($v3))->toBeFalse()
        ->and($schema->reference())->toBe('communication.message@3');
});

it('refuses malformed normalizer identities', function (string $id): void {
    new NormalizerIdentity($id, 1);
})->with(['Shell Command', 'Shell', '', 'shell_command'])->throws(InvalidArgumentException::class);

it('refuses a version below one', function (): void {
    new NormalizerIdentity('shell-command', 0);
})->throws(InvalidArgumentException::class);

it('hashes raw evidence so a candidate can name exactly what it came from', function (): void {
    $reference = RawReference::forPayload('src', 'res', 'ls -la');

    expect($reference->inputHash)->toBe(hash('sha256', 'ls -la'))
        ->and($reference->matches('ls -la'))->toBeTrue()
        ->and($reference->matches('ls -l'))->toBeFalse();
});

it('refuses raw evidence without a sha256 hash', function (): void {
    new RawReference('src', 'res', 'not-a-hash');
})->throws(InvalidArgumentException::class);
