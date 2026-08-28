<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

use InvalidArgumentException;

final readonly class GitRepository
{
    public function __construct(
        public string $reference,
        public string $name,
        public ?string $remoteUrl = null,
    ) {
        if (trim($reference) === '' || trim($name) === '') {
            throw new InvalidArgumentException('A Git repository requires a stable reference and name.');
        }
    }
}
