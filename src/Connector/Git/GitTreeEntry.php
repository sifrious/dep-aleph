<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

use InvalidArgumentException;

final readonly class GitTreeEntry
{
    public function __construct(
        public string $path,
        public string $blobSha,
        public string $mode = '100644',
        public ?string $content = null,
    ) {
        if (trim($path) === '' || preg_match('/^[a-f0-9]{40}$/', $blobSha) !== 1) {
            throw new InvalidArgumentException('A Git tree entry requires a path and full lowercase blob SHA-1.');
        }
    }
}
