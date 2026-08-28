<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LinearActivity
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<LinearAttachmentReference>  $attachments
     */
    public function __construct(
        public LinearActivityKind $kind,
        public string $workspaceReference,
        public string $providerId,
        public DateTimeImmutable $updatedAt,
        public array $payload,
        public array $attachments = [],
    ) {
        if (trim($workspaceReference) === '' || trim($providerId) === '') {
            throw new InvalidArgumentException('Linear activity requires workspace and provider identity.');
        }
    }

    public function resourceReference(): string
    {
        return implode('/', [$this->workspaceReference, $this->kind->value, rawurlencode($this->providerId)]);
    }

    public function revision(): string
    {
        return $this->updatedAt->format('U.u').':'.hash('sha256', $this->contents());
    }

    public function contents(): string
    {
        return json_encode([
            'kind' => $this->kind->value,
            'workspace' => $this->workspaceReference,
            'provider_id' => $this->providerId,
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
            'payload' => $this->payload,
            'attachments' => array_map(
                static fn (LinearAttachmentReference $attachment): array => $attachment->toArray(),
                $this->attachments,
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
