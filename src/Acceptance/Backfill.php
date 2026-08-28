<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Acceptance;

use DateTimeImmutable;
use Sifrious\Aleph\Envelope\DiscoveryReference;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\ObservationMetadata;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\Aleph\Normalization\CandidateEnvelope;
use Sifrious\Funes\Acceptance\AcceptanceBacklog;
use Sifrious\Funes\Value\Discovery;
use Sifrious\Funes\Value\Observation;
use Throwable;

final readonly class Backfill
{
    public const CONNECTOR = 'aleph.backfill';

    public function __construct(
        private AcceptanceBacklog $backlog,
        private AcceptanceClient $acceptance,
    ) {}

    public function run(int $batch = 100, ?string $attemptId = null): BackfillReport
    {
        $accepted = 0;
        $replayed = 0;
        $rejected = 0;
        $failed = 0;
        $failures = [];
        $observations = $this->backlog->unkeyed($batch);

        foreach ($observations as $observation) {
            try {
                $record = $this->acceptance->submit(
                    $this->candidateFor($observation),
                    $attemptId ?? self::CONNECTOR,
                );
            } catch (Throwable $failure) {
                $failed++;
                $failures[] = $observation->resourceReference.': '.$failure->getMessage();

                continue;
            }

            match ($record->submission->status) {
                SubmissionStatus::Accepted => $accepted++,
                SubmissionStatus::Replayed => $replayed++,
                SubmissionStatus::Rejected => $rejected++,
                default => $failed++,
            };

            if (! $record->isAuthoritative()) {
                $failures[] = $observation->resourceReference.': '
                    .($record->submission->error ?? $record->submission->status->value);
            }
        }

        return new BackfillReport(
            examined: count($observations),
            accepted: $accepted,
            replayed: $replayed,
            rejected: $rejected,
            failed: $failed,
            failures: $failures,
        );
    }

    private function candidateFor(Observation $observation): CandidateEnvelope
    {
        return CandidateEnvelope::forEnvelope($this->envelopeFor($observation));
    }

    private function envelopeFor(Observation $observation): ObservationEnvelope
    {
        $aleph = ObservationMetadata::aleph($observation);

        return new ObservationEnvelope(
            sourceReference: $observation->sourceReference,
            sourceName: $observation->sourceName,
            resourceReference: $observation->resourceReference,
            observedAt: $observation->observedAt,
            payload: $observation->payload,
            provenance: $this->provenanceFor($observation, $aleph),
            contentType: $observation->contentType,
            account: $this->string($aleph['account'] ?? null),
            stream: $this->string($aleph['stream'] ?? null),
            eventType: $this->string($aleph['event_type'] ?? null),
            providerId: $this->string($aleph['provider_id'] ?? null),
            providerRevision: $this->string($aleph['provider_revision'] ?? null),
            extensions: ObservationMetadata::extensions($observation),
            discoveries: $this->discoveriesFor($observation),
            occurredAt: $this->time($aleph['occurred_at'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $aleph
     */
    private function provenanceFor(Observation $observation, array $aleph): Provenance
    {
        $recorded = is_array($aleph['provenance'] ?? null) ? $aleph['provenance'] : [];

        return new Provenance(
            connectorId: $this->string($recorded['connector'] ?? null) ?? self::CONNECTOR,
            connectorVersion: $this->string($recorded['connector_version'] ?? null) ?? '1.0.0',
            installationId: $this->string($recorded['installation'] ?? null) ?? $observation->sourceReference,
            capturedAt: $this->time($recorded['captured_at'] ?? null) ?? $observation->observedAt,
            runId: $this->string($recorded['run'] ?? null),
            details: ['backfilled_from' => $observation->id],
        );
    }

    /**
     * @return list<DiscoveryReference>
     */
    private function discoveriesFor(Observation $observation): array
    {
        $discoveries = is_array($observation->discoveries) ? $observation->discoveries : [];

        return array_values(array_map(
            static fn (Discovery $discovery): DiscoveryReference => new DiscoveryReference(
                $discovery->canonicalReference,
                $discovery->relationship,
            ),
            array_filter($discoveries, static fn (mixed $item): bool => $item instanceof Discovery),
        ));
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function time(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
