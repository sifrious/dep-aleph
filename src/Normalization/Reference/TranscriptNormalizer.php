<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Normalization\Reference;

use DateTimeImmutable;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Normalization\CandidateEnvelope;
use Sifrious\Aleph\Normalization\CandidateEnvelopes;
use Sifrious\Aleph\Normalization\CandidateSchema;
use Sifrious\Aleph\Normalization\MalformedInput;
use Sifrious\Aleph\Normalization\NormalizationInput;
use Sifrious\Aleph\Normalization\Normalizer;
use Sifrious\Aleph\Normalization\NormalizerIdentity;

final readonly class TranscriptNormalizer implements Normalizer
{
    private const ROLES = [
        'human' => 'user',
        'user' => 'user',
        'assistant' => 'assistant',
        'model' => 'assistant',
        'system' => 'system',
    ];

    public function identity(): NormalizerIdentity
    {
        return new NormalizerIdentity('transcript', 2);
    }

    public function schema(): CandidateSchema
    {
        return new CandidateSchema('communication.message', 3);
    }

    public function supports(NormalizationInput $input): bool
    {
        return in_array($input->contentType(), ['application/json', 'application/x-ndjson', null], true);
    }

    public function normalize(NormalizationInput $input): CandidateEnvelopes
    {
        $decoded = json_decode($input->payload, true);

        if (! is_array($decoded) || ! isset($decoded['messages']) || ! is_array($decoded['messages'])) {
            throw MalformedInput::because('Transcript payload must be a JSON object containing a messages array.');
        }

        $session = (string) ($decoded['session_id'] ?? 'unknown');
        $candidates = [];

        foreach ($decoded['messages'] as $position => $message) {
            if (! is_array($message) || ! isset($message['role'], $message['text'])) {
                continue;
            }

            $role = self::ROLES[strtolower((string) $message['role'])] ?? null;

            if ($role === null) {
                continue;
            }

            $candidates[] = new CandidateEnvelope(
                $this->schema(),
                $this->identity(),
                $input->raw,
                new ObservationEnvelope(
                    sourceReference: $input->raw->sourceReference,
                    sourceName: $input->raw->sourceReference,
                    resourceReference: $input->raw->resourceReference.'#'.$position,
                    observedAt: $this->timestamp($message, $input),
                    payload: (string) $message['text'],
                    provenance: $input->provenance,
                    contentType: 'text/plain',
                    stream: 'session/'.$session,
                    eventType: 'communication.message.recorded',
                    providerId: (string) ($message['id'] ?? $session.':'.$position),
                    extensions: [
                        new ExtensionMetadata('transcript.message', 1, [
                            'session_id' => $session,
                            'role' => $role,
                            'original_role' => (string) $message['role'],
                            'position' => $position,
                        ]),
                    ],
                ),
            );
        }

        return new CandidateEnvelopes(...$candidates);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function timestamp(array $message, NormalizationInput $input): DateTimeImmutable
    {
        $raw = $message['at'] ?? null;

        if (! is_string($raw)) {
            return $input->provenance->capturedAt;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable) {
            return $input->provenance->capturedAt;
        }
    }
}
