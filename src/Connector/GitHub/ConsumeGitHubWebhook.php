<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Values\WebhookDelivery;

final readonly class ConsumeGitHubWebhook
{
    public function __construct(
        private GitHubWebhookSecrets $secrets,
        private GitHubWebhookVerifier $verifier,
        private GitHubWebhookDeliveries $deliveries,
        private GitHubWebhookNormalizer $normalizer,
        private ConnectorInstallations $installations,
        private GitHubActivitySubmitter $submitter,
    ) {}

    /**
     * @return list<string>
     */
    public function consume(WebhookDelivery $delivery): array
    {
        $installation = $this->installations->find($delivery->sourceReference);

        if ($installation === null || $installation->connectorId !== 'github-activity') {
            throw new InvalidArgumentException('GitHub webhook source must identify a GitHub activity installation.');
        }

        $signature = $delivery->signature ?? $delivery->header('X-Hub-Signature-256') ?? '';

        if (! $this->verifier->verify($delivery->body, $signature, $this->secrets->get($installation->id))) {
            throw new InvalidArgumentException('GitHub webhook signature is invalid.');
        }

        $deliveryId = $delivery->header('X-GitHub-Delivery') ?? '';
        $event = $delivery->header('X-GitHub-Event') ?? '';
        $record = $this->deliveries->persist($installation->id, $deliveryId, $event, $delivery->body);

        if ($record->processed()) {
            return $record->acceptedReferences;
        }

        $accepted = [];

        foreach ($this->normalizer->normalize($event, $record->payload) as $activity) {
            $accepted[] = $this->submitter->submit(
                $activity,
                $installation->id,
                $installation->externalAccountId,
                new DateTimeImmutable,
                'webhook',
                deliveryId: $deliveryId,
            );
        }

        return $this->deliveries->markProcessed($record, array_values(array_unique($accepted)))->acceptedReferences;
    }
}
