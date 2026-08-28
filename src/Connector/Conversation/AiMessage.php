<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AiMessage
{
    /**
     * @param  list<AiMessagePart>  $parts
     * @param  array<string, mixed>  $providerRecord
     */
    public function __construct(
        public string $providerId,
        public ?string $parentProviderId,
        public int $ordinal,
        public AiMessageRole $role,
        public string $author,
        public array $parts,
        public ?DateTimeImmutable $occurredAt,
        public ?string $threadId = null,
        public ?string $branchId = null,
        public bool $sidechain = false,
        public ?string $agentId = null,
        public array $providerRecord = [],
        public ?string $rawReference = null,
    ) {
        if (trim($providerId) === '' || trim($author) === '' || $ordinal < 0) {
            throw new InvalidArgumentException('AI message requires an id, author, and non-negative ordinal.');
        }

    }

    public function text(): string
    {
        return implode("\n", array_values(array_filter(
            array_map(static fn (AiMessagePart $part): ?string => $part->text, $this->parts),
            static fn (?string $text): bool => $text !== null && $text !== '',
        )));
    }
}
