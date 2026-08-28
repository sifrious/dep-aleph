<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use InvalidArgumentException;

final readonly class CommunicationPage
{
    /** @param list<F28CommunicationRecord> $records */
    public function __construct(
        public array $records,
        public ?string $checkpoint,
        public bool $hasMore,
    ) {
        if ($hasMore && ($checkpoint === null || $checkpoint === '')) {
            throw new InvalidArgumentException('A continuing communication page requires a checkpoint.');
        }
    }
}
