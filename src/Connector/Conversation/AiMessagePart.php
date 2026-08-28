<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

use InvalidArgumentException;

final readonly class AiMessagePart
{
    /**
     * @param  array<string, mixed>  $providerBlock
     */
    public function __construct(
        public string $type,
        public ?string $text,
        public ?string $toolCallId,
        public array $providerBlock,
    ) {
        if (trim($type) === '') {
            throw new InvalidArgumentException('AI message part type cannot be empty.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'text' => $this->text,
            'tool_call_id' => $this->toolCallId,
            'provider_block' => $this->providerBlock,
        ];
    }
}
