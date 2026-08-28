<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Scope;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Sifrious\Funes\Value\EntityKind;
use Sifrious\Funes\Value\EntityReference;
use stdClass;

final readonly class SourceScopeAssociations
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function associate(
        string $sourceInstallationId,
        EntityReference $scope,
        AssociationState $state = AssociationState::Confirmed,
        ?string $stream = null,
        ?string $role = null,
        array $metadata = [],
    ): SourceScopeAssociation {
        $this->assertInstallationExists($sourceInstallationId);

        $key = hash('sha256', implode("\0", [
            $sourceInstallationId,
            $stream ?? '',
            $scope->kind->value,
            $scope->id,
            $role ?? '',
        ]));
        $existing = $this->table()->where('association_key', $key)->first();
        $now = Carbon::now();

        if ($existing instanceof stdClass) {
            $existingMetadata = json_decode((string) $existing->metadata, true, 512, JSON_THROW_ON_ERROR);

            if ((string) $existing->state === $state->value && $existingMetadata === $metadata) {
                return $this->hydrate($existing);
            }

            $this->table()->where('association_key', $key)->update([
                'state' => $state->value,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

            return $this->findByKey($key);
        }

        $this->table()->insert([
            'id' => (string) Str::ulid(),
            'association_key' => $key,
            'source_installation_id' => $sourceInstallationId,
            'stream' => $stream,
            'scope_type' => $scope->kind->value,
            'scope_id' => $scope->id,
            'role' => $role,
            'state' => $state->value,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByKey($key);
    }

    /**
     * @return list<SourceScopeAssociation>
     */
    public function forInstallation(string $sourceInstallationId, ?string $stream = null): array
    {
        $query = $this->table()->where('source_installation_id', $sourceInstallationId);

        if ($stream === null) {
            $query->whereNull('stream');
        } else {
            $query->where(fn (Builder $scope): Builder => $scope
                ->whereNull('stream')
                ->orWhere('stream', $stream));
        }

        return array_values(array_map(
            fn (stdClass $row): SourceScopeAssociation => $this->hydrate($row),
            $query->orderBy('scope_type')->orderBy('scope_id')->orderBy('role')->get()->all(),
        ));
    }

    /**
     * @return list<SourceScopeAssociation>
     */
    public function allForInstallation(string $sourceInstallationId): array
    {
        return array_values(array_map(
            fn (stdClass $row): SourceScopeAssociation => $this->hydrate($row),
            $this->table()
                ->where('source_installation_id', $sourceInstallationId)
                ->orderBy('stream')
                ->orderBy('scope_type')
                ->orderBy('scope_id')
                ->orderBy('role')
                ->get()
                ->all(),
        ));
    }

    /**
     * @return array{state: string, associations: list<array<string, mixed>>}
     */
    public function snapshot(string $sourceInstallationId, ?string $stream = null): array
    {
        $associations = $this->forInstallation($sourceInstallationId, $stream);
        $active = array_values(array_filter(
            $associations,
            static fn (SourceScopeAssociation $association): bool => in_array(
                $association->state,
                [AssociationState::Confirmed, AssociationState::Ambiguous],
                true,
            ),
        ));

        return [
            'state' => $active === []
                ? 'unassigned'
                : (in_array(AssociationState::Ambiguous, array_column($active, 'state'), true) ? 'ambiguous' : 'assigned'),
            'associations' => array_map(
                static fn (SourceScopeAssociation $association): array => $association->toArray(),
                $associations,
            ),
        ];
    }

    private function assertInstallationExists(string $id): void
    {
        if (! $this->connection->table('aleph_connector_installations')->where('id', $id)->exists()) {
            throw UnknownSourceInstallation::withId($id);
        }
    }

    private function findByKey(string $key): SourceScopeAssociation
    {
        return $this->hydrate($this->table()->where('association_key', $key)->firstOrFail());
    }

    private function hydrate(stdClass $row): SourceScopeAssociation
    {
        $metadata = json_decode((string) $row->metadata, true, 512, JSON_THROW_ON_ERROR);

        return new SourceScopeAssociation(
            id: (string) $row->id,
            sourceInstallationId: (string) $row->source_installation_id,
            stream: $row->stream === null ? null : (string) $row->stream,
            scope: new EntityReference(EntityKind::from((string) $row->scope_type), (string) $row->scope_id),
            state: AssociationState::from((string) $row->state),
            role: $row->role === null ? null : (string) $row->role,
            metadata: is_array($metadata) ? $metadata : [],
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: new DateTimeImmutable((string) $row->updated_at),
        );
    }

    private function table(): Builder
    {
        return $this->connection->table('aleph_source_scope_associations');
    }
}
