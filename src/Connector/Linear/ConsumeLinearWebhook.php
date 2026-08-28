<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Values\WebhookDelivery;

final readonly class ConsumeLinearWebhook
{
    public function __construct(
        private LinearWebhookSecrets $secrets,
        private LinearWebhookVerifier $verifier,
        private LinearWebhookDeliveries $deliveries,
        private LinearWebhookNormalizer $normalizer,
        private ConnectorInstallations $installations,
        private LinearActivitySubmitter $submitter,
    ) {}

    /** @return list<string> */
    public function consume(WebhookDelivery $delivery): array
    {
        $installation = $this->installations->find($delivery->sourceReference);

        if ($installation === null || $installation->connectorId !== 'linear-activity') {
            throw new InvalidArgumentException('Linear webhook source must identify a Linear activity installation.');
        }

        $signature = $delivery->signature ?? $delivery->header('Linear-Signature') ?? '';

        if (! $this->verifier->verify($delivery->body, $signature, $this->secrets->get($installation->id))) {
            throw new InvalidArgumentException('Linear webhook signature is invalid.');
        }

        $deliveryId = $delivery->header('Linear-Delivery') ?? '';
        $event = $delivery->header('Linear-Event') ?? 'activity';
        $record = $this->deliveries->persist($installation->id, $deliveryId, $event, $delivery->body);

        if ($record->processed()) {
            return $record->acceptedReferences;
        }

        $workspace = $installation->externalAccountId;

        if ($workspace === null || trim($workspace) === '') {
            throw new InvalidArgumentException('Linear installation requires a workspace reference.');
        }

        $accepted = [];

        foreach ($this->normalizer->normalize($workspace, $record->payload) as $activity) {
            $accepted[] = $this->submitter->submit(
                $activity,
                $installation->id,
                $workspace,
                new DateTimeImmutable,
                'webhook',
                deliveryId: $deliveryId,
            );
        }

        return $this->deliveries->markProcessed($record, array_values(array_unique($accepted)))->acceptedReferences;
    }
}
