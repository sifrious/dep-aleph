<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use InvalidArgumentException;

final readonly class EmailParticipant
{
    public function __construct(
        public string $role,
        public string $original,
        public ?string $address,
        public ?string $name = null,
    ) {
        if (trim($role) === '' || trim($original) === '') {
            throw new InvalidArgumentException('Email participants require role and original source value.');
        }
    }

    /** @return array<string, ?string> */
    public function toArray(): array
    {
        return ['role' => $this->role, 'original' => $this->original, 'address' => $this->address, 'name' => $this->name];
    }
}
