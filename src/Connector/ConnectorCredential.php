<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

use DateTimeImmutable;

final readonly class ConnectorCredential
{
    /**
     * @param  array<string, string>  $material
     * @param  list<string>  $scopes
     * @param  array<string, int|float|string|bool|null>  $refreshMetadata
     */
    public function __construct(
        public string $id,
        public string $sourceInstallationId,
        public string $reference,
        public CredentialKind $kind,
        public array $material,
        public array $scopes,
        public array $refreshMetadata,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $refreshedAt,
        public DateTimeImmutable $createdAt,
    ) {}

    public function expiredAt(DateTimeImmutable $at): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $at;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'id' => $this->id,
            'source_installation_id' => $this->sourceInstallationId,
            'reference' => $this->reference,
            'kind' => $this->kind->value,
            'scopes' => $this->scopes,
            'refresh_metadata' => $this->refreshMetadata,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'refreshed_at' => $this->refreshedAt?->format(DATE_ATOM),
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
