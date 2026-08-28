<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SlackActivity
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $relationships
     */
    public function __construct(
        public SlackActivityKind $kind,
        public string $workspaceReference,
        public string $providerId,
        public string $revision,
        public DateTimeImmutable $occurredAt,
        public array $payload,
        public array $relationships = [],
        public ?string $channelReference = null,
        public string $rawReference = '',
    ) {
        if (trim($workspaceReference) === '' || trim($providerId) === '' || trim($revision) === '') {
            throw new InvalidArgumentException('Slack activities require workspace, provider, and revision identities.');
        }
    }

    public function resourceReference(): string
    {
        return $this->workspaceReference.'/'.$this->kind->value.'/'.$this->providerId;
    }

    /** @return array<string, mixed> */
    public function contents(): array
    {
        return [
            'kind' => $this->kind->value,
            'workspace_reference' => $this->workspaceReference,
            'channel_reference' => $this->channelReference,
            'provider_id' => $this->providerId,
            'provider_revision' => $this->revision,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'relationships' => $this->relationships,
            'payload' => $this->payload,
        ];
    }
}
