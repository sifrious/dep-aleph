<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use InvalidArgumentException;

final readonly class EmailPage
{
    /** @param list<F28EmailMessage> $messages */
    public function __construct(
        public array $messages,
        public ?string $checkpoint,
        public bool $hasMore,
    ) {
        if ($hasMore && ($checkpoint === null || $checkpoint === '')) {
            throw new InvalidArgumentException('A continuing email page requires a checkpoint.');
        }
    }
}
