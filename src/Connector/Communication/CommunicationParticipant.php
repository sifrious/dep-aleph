<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use InvalidArgumentException;

final readonly class CommunicationParticipant
{
    public function __construct(
        public string $providerId,
        public string $role,
        public string $kind,
        public ?string $originalAddress = null,
        public ?string $normalizedAddress = null,
        public ?string $displayName = null,
    ) {
        if (trim($providerId) === '' || trim($role) === '' || trim($kind) === '') {
            throw new InvalidArgumentException('Communication participants require provider identity, role, and kind.');
        }
    }

    /** @return array<string, ?string> */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'role' => $this->role,
            'kind' => $this->kind,
            'original_address' => $this->originalAddress,
            'normalized_address' => $this->normalizedAddress,
            'display_name' => $this->displayName,
        ];
    }
}
