<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Shell;

use InvalidArgumentException;

final readonly class ShellExecutionContext
{
    public function __construct(
        public string $host,
        public string $user,
        public string $shell,
        public ?string $cwd = null,
        public ?string $session = null,
        public ?string $environmentReference = null,
    ) {
        if (trim($host) === '' || trim($user) === '' || trim($shell) === '') {
            throw new InvalidArgumentException('Shell execution context requires host, user, and shell identities.');
        }
    }
}
