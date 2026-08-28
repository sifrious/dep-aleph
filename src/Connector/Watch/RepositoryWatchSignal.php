<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Watch;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RepositoryWatchSignal
{
    public function __construct(
        public RepositoryWatchSignalOrigin $origin,
        public string $ref,
        public string $headReference,
        public DateTimeImmutable $observedAt,
        public ?string $previousHeadReference = null,
        public bool $forcePushed = false,
    ) {
        if (trim($ref) === '' || trim($headReference) === '') {
            throw new InvalidArgumentException('A repository watch signal requires stable ref and head identities.');
        }
    }

    public function triggerKey(RepositoryWatch $watch): string
    {
        return hash('sha256', implode('|', [$watch->id, $this->ref, $this->headReference]));
    }

    public function fullReconciliation(): bool
    {
        return $this->origin !== RepositoryWatchSignalOrigin::Webhook;
    }
}
