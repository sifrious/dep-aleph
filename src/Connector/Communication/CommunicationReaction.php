<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

final readonly class CommunicationReaction
{
    /** @param list<string> $participantIds */
    public function __construct(
        public string $value,
        public int $count,
        public array $participantIds = [],
    ) {}

    /** @return array{value: string, count: int, participant_ids: list<string>} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'count' => $this->count, 'participant_ids' => $this->participantIds];
    }
}
