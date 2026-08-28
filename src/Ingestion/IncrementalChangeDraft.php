<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class IncrementalChangeDraft
{
    public function __construct(
        public string $sourceChangeId,
        public ChangeKind $kind,
        public string $resourceReference,
        public string $fingerprint,
        public ?string $observationReference,
        public DateTimeImmutable $occurredAt,
    ) {
        if (trim($sourceChangeId) === '' || trim($resourceReference) === '') {
            throw new InvalidArgumentException('An incremental change requires stable source and resource identities.');
        }

        if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('An incremental change fingerprint must be a lowercase sha256 hash.');
        }

        if ($kind !== ChangeKind::Unchanged && trim((string) $observationReference) === '') {
            throw new InvalidArgumentException('A material incremental change requires an accepted observation reference.');
        }
    }
}
