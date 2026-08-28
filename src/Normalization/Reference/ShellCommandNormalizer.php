<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization\Reference;

use DateTimeImmutable;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Normalization\CandidateEnvelope;
use Sifrious\Aleph\Normalization\CandidateEnvelopes;
use Sifrious\Aleph\Normalization\CandidateSchema;
use Sifrious\Aleph\Normalization\NormalizationInput;
use Sifrious\Aleph\Normalization\Normalizer;
use Sifrious\Aleph\Normalization\NormalizerIdentity;

final readonly class ShellCommandNormalizer implements Normalizer
{
    public function identity(): NormalizerIdentity
    {
        return new NormalizerIdentity('shell-command', 3);
    }

    public function schema(): CandidateSchema
    {
        return new CandidateSchema('activity.command', 2);
    }

    public function supports(NormalizationInput $input): bool
    {
        return in_array($input->contentType(), ['text/x-shellscript', 'text/plain', null], true);
    }

    public function normalize(NormalizationInput $input): CandidateEnvelopes
    {
        $command = trim($input->payload);

        if ($command === '') {
            return CandidateEnvelopes::none();
        }

        $argv = $this->argv($command);

        if ($argv === []) {
            return CandidateEnvelopes::none();
        }

        $envelope = new ObservationEnvelope(
            sourceReference: $input->raw->sourceReference,
            sourceName: $input->raw->sourceReference,
            resourceReference: $input->raw->resourceReference,
            observedAt: $this->observedAt($input),
            payload: $command,
            provenance: $input->provenance,
            contentType: 'text/plain',
            eventType: 'activity.command.executed',
            extensions: [
                new ExtensionMetadata('shell.command', 1, [
                    'binary' => $argv[0],
                    'argv' => $argv,
                    'argument_count' => count($argv) - 1,
                    'interactive' => $this->looksInteractive($argv[0]),
                ]),
            ],
        );

        return new CandidateEnvelopes(
            new CandidateEnvelope($this->schema(), $this->identity(), $input->raw, $envelope),
        );
    }

    /**
     * @return list<string>
     */
    private function argv(string $command): array
    {
        if ($this->hasUnbalancedQuotes($command)) {
            return [];
        }

        $tokens = preg_split('/(?<!\\\\)\s+/', $command, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        return array_map(
            static fn (string $token): string => trim($token, "'\""),
            $tokens,
        );
    }

    private function hasUnbalancedQuotes(string $command): bool
    {
        return substr_count($command, '"') % 2 !== 0 || substr_count($command, "'") % 2 !== 0;
    }

    private function looksInteractive(string $binary): bool
    {
        return in_array($binary, ['vim', 'vi', 'nano', 'less', 'top', 'htop', 'ssh'], true);
    }

    private function observedAt(NormalizationInput $input): DateTimeImmutable
    {
        $stamp = $input->contextValue('observed_at');

        return $stamp instanceof DateTimeImmutable ? $stamp : $input->provenance->capturedAt;
    }
}
