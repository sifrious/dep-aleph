<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class DomainIngestionRun
{
    /**
     * @param  list<string>  $domainReferences
     * @param  list<string>  $providerReconciliationIds
     */
    public function __construct(
        public IngestionRunReadModel $ingestion,
        public DomainRunScope $scope,
        public string $providerAccountReference,
        public array $domainReferences,
        public array $providerReconciliationIds,
    ) {}

    public static function from(IngestionRunReadModel $ingestion): self
    {
        if ($ingestion->run->connectorId !== 'dns') {
            throw new InvalidArgumentException('A domain ingestion projection requires the dns connector.');
        }

        $extensions = $ingestion->run->parameters['extensions'] ?? [];
        $dns = is_array($extensions) && is_array($extensions['dns'] ?? null) ? $extensions['dns'] : [];

        return new self(
            ingestion: $ingestion,
            scope: DomainRunScope::from((string) ($dns['scope'] ?? 'account')),
            providerAccountReference: (string) ($dns['provider_account'] ?? $ingestion->run->sourceReference),
            domainReferences: self::strings($dns['domains'] ?? []),
            providerReconciliationIds: self::strings($dns['provider_reconciliation_ids'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run' => $this->ingestion->toArray(),
            'dns' => [
                'scope' => $this->scope->value,
                'provider_account' => $this->providerAccountReference,
                'domains' => $this->domainReferences,
                'provider_reconciliation_ids' => $this->providerReconciliationIds,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(strval(...), $values));
    }
}
