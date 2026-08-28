<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Git;

final readonly class GitFileChange
{
    public function __construct(
        public GitChangeKind $kind,
        public string $path,
        public ?string $previousPath,
        public ?string $previousBlobSha,
        public ?string $blobSha,
    ) {}
}
