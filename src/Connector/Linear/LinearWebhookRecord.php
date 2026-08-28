<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use DateTimeImmutable;

final readonly class LinearWebhookRecord
{
    /** @param list<string> $acceptedReferences */
    public function __construct(
        public string $id,
        public string $sourceInstallationId,
        public string $deliveryId,
        public string $event,
        public string $payload,
        public array $acceptedReferences,
        public DateTimeImmutable $receivedAt,
        public ?DateTimeImmutable $processedAt,
    ) {}

    public function processed(): bool
    {
        return $this->processedAt !== null;
    }
}
