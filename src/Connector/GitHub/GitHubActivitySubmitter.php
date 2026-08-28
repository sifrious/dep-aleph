<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class GitHubActivitySubmitter
{
    public function __construct(private EnvelopeSubmitter $submitter) {}

    public function submit(
        GitHubActivity $activity,
        string $installationId,
        ?string $account,
        DateTimeImmutable $capturedAt,
        string $transport,
        ?string $runId = null,
        ?string $attemptId = null,
        ?string $deliveryId = null,
    ): string {
        $outcome = $this->submitter->submit(new ObservationEnvelope(
            sourceReference: 'github:'.strtolower($activity->repository),
            sourceName: $activity->repository,
            resourceReference: $activity->resourceReference(),
            observedAt: $capturedAt,
            payload: $activity->contents(),
            provenance: new Provenance('github-activity', '1.0.0', $installationId, $capturedAt, $runId, [
                'transport' => $transport,
                'delivery_id' => $deliveryId,
            ]),
            contentType: 'application/json',
            account: $account,
            stream: strtolower($activity->repository),
            eventType: 'github.'.$activity->kind->value,
            providerId: $activity->nodeId,
            providerRevision: $activity->revision(),
            extensions: [new ExtensionMetadata('github.activity', 1, [
                'repository' => $activity->repository,
                'transport' => $transport,
            ])],
            occurredAt: $activity->updatedAt,
        ), $attemptId);
        $accepted = $outcome->acceptedId();

        if (! $outcome->isAuthoritative() || $accepted === null) {
            throw new InvalidArgumentException('Funes did not accept the GitHub activity.');
        }

        return $accepted;
    }
}
