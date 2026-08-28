<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class CommunicationRecordSubmitter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function submit(F28CommunicationRecord $record, string $installationId, ?string $account, ?string $runId, ?string $attemptId): string
    {
        $capturedAt = new DateTimeImmutable;
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: $record->sourceReference,
            sourceName: $record->sourceReference,
            resourceReference: $record->resourceReference(),
            observedAt: $capturedAt,
            payload: json_encode($record->toArray(), JSON_THROW_ON_ERROR),
            provenance: new Provenance($record->provider->value, '1.0.0', $installationId, $capturedAt, $runId, ['raw_reference' => $record->rawReference]),
            contentType: 'application/json',
            account: $account,
            stream: $record->conversationId,
            eventType: 'communication.'.$record->provider->value,
            providerId: $record->providerId,
            providerRevision: $record->providerRevision,
            extensions: [new ExtensionMetadata('communication.f28', 1, [
                'provider' => $record->provider->value,
                'change' => $record->change->value,
                'conversation_kind' => $record->conversationKind,
                'attachment_references' => array_map(static fn (CommunicationAttachment $attachment): array => $attachment->toArray(), $record->attachments),
            ])],
            occurredAt: $record->occurredAt,
        ), $attemptId);
        $accepted = $outcome->acceptedId();

        if (! $outcome->isAuthoritative() || $accepted === null) {
            throw new InvalidArgumentException('Funes did not accept the communication record.');
        }

        return $accepted;
    }
}
