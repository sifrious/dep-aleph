<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Bibliography;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final readonly class BibliographicCatalog
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @param  list<SourceIdentifier>  $identifiers
     */
    public function upsertResource(
        SourceIdentifier $sourceIdentifier,
        ?string $canonicalUri = null,
        array $identifiers = [],
        string $resourceType = 'source',
        ?string $language = null,
        array $metadata = [],
    ): Resource {
        $canonicalUri = $this->optional($canonicalUri);
        $resourceType = trim($resourceType);
        $language = $this->optional($language);

        if ($resourceType === '') {
            throw new InvalidArgumentException('A resource type cannot be empty.');
        }

        if ($canonicalUri !== null && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $canonicalUri) !== 1) {
            throw new InvalidArgumentException('A resource requires an absolute canonical URI.');
        }

        /** @var Resource */
        return $this->connection->transaction(function () use ($sourceIdentifier, $canonicalUri, $identifiers, $resourceType, $language, $metadata): Resource {
            $row = $this->sourceRow('aleph_resources', $sourceIdentifier);

            if ($row instanceof stdClass) {
                $this->assertSame('resource', (string) $row->id, 'resource_type', (string) $row->resource_type, $resourceType);

                if ($row->canonical_uri !== null && $canonicalUri !== null) {
                    $this->assertSame('resource', (string) $row->id, 'canonical_uri', (string) $row->canonical_uri, $canonicalUri);
                }

                $updates = [];

                if ($row->canonical_uri === null && $canonicalUri !== null) {
                    $updates['canonical_uri'] = $canonicalUri;
                }

                if ($row->language === null && $language !== null) {
                    $updates['language'] = $language;
                }

                $existingMetadata = $this->decodeArray($row->metadata);
                $mergedMetadata = $this->fillArray($existingMetadata, $metadata);

                if ($mergedMetadata !== $existingMetadata) {
                    $updates['metadata'] = json_encode($mergedMetadata, JSON_THROW_ON_ERROR);
                }

                $existingIdentifiers = $this->decodeIdentifiers($row->identifiers);
                $mergedIdentifiers = $this->mergeIdentifiers($existingIdentifiers, [$sourceIdentifier, ...$identifiers]);

                if ($mergedIdentifiers !== $existingIdentifiers) {
                    $updates['identifiers'] = $this->encodeIdentifierList($mergedIdentifiers);
                }

                $this->updateIfNeeded('aleph_resources', (string) $row->id, $updates);

                return $this->resource((string) $row->id);
            }

            $now = Carbon::now();
            $id = (string) Str::ulid();
            $this->table('aleph_resources')->insert([
                'id' => $id,
                'identity_key' => $sourceIdentifier->key(),
                'source' => $sourceIdentifier->source,
                'source_identifier' => $sourceIdentifier->identifier,
                'resource_type' => $resourceType,
                'canonical_uri' => $canonicalUri,
                'language' => $language,
                'metadata' => json_encode($this->normalizeArray($metadata), JSON_THROW_ON_ERROR),
                'identifiers' => $this->encodeIdentifiers($sourceIdentifier, $identifiers),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->resource($id);
        });
    }

    /**
     * @param  list<SourceIdentifier>  $identifiers
     */
    public function upsertAuthor(
        SourceIdentifier $sourceIdentifier,
        ?string $name = null,
        array $identifiers = [],
    ): Author {
        /** @var Author */
        return $this->connection->transaction(function () use ($sourceIdentifier, $name, $identifiers): Author {
            $row = $this->sourceRow('aleph_authors', $sourceIdentifier);

            if ($row instanceof stdClass) {
                $this->enrichSourceRow('aleph_authors', $row, ['name' => $this->optional($name)], $sourceIdentifier, $identifiers);

                return $this->author((string) $row->id);
            }

            $id = $this->insertSourceRow('aleph_authors', $sourceIdentifier, [
                'name' => $this->optional($name),
            ], $identifiers);

            return $this->author($id);
        });
    }

    /**
     * @param  list<SourceIdentifier>  $identifiers
     */
    public function upsertBook(
        SourceIdentifier $sourceIdentifier,
        ?string $title = null,
        ?string $language = null,
        array $identifiers = [],
    ): Book {
        /** @var Book */
        return $this->connection->transaction(function () use ($sourceIdentifier, $title, $language, $identifiers): Book {
            $row = $this->sourceRow('aleph_books', $sourceIdentifier);
            $fields = ['title' => $this->optional($title), 'language' => $this->optional($language)];

            if ($row instanceof stdClass) {
                $this->enrichSourceRow('aleph_books', $row, $fields, $sourceIdentifier, $identifiers);

                return $this->book((string) $row->id);
            }

            $id = $this->insertSourceRow('aleph_books', $sourceIdentifier, $fields, $identifiers);

            return $this->book($id);
        });
    }

    public function attachAuthor(
        BookId $bookId,
        AuthorId $authorId,
        string $role,
        ?int $position = null,
    ): BookAuthor {
        $role = strtolower(trim($role));

        if ($role === '' || ($position !== null && $position < 0)) {
            throw new InvalidArgumentException('A creator role must be non-empty and its position cannot be negative.');
        }

        /** @var BookAuthor */
        return $this->connection->transaction(function () use ($bookId, $authorId, $role, $position): BookAuthor {
            $key = hash('sha256', implode("\0", [$bookId->value, $authorId->value, $role]));
            $row = $this->table('aleph_book_authors')->where('identity_key', $key)->first();

            if (! $row instanceof stdClass) {
                $now = Carbon::now();
                $id = (string) Str::ulid();
                $this->table('aleph_book_authors')->insert([
                    'id' => $id,
                    'identity_key' => $key,
                    'book_id' => $bookId->value,
                    'author_id' => $authorId->value,
                    'role' => $role,
                    'position' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $this->bookAuthor($id);
            }

            if ($row->position !== null && $position !== null && (int) $row->position !== $position) {
                throw ImmutableBibliographicConflict::forField('book_author', (string) $row->id, 'position');
            }

            if ($row->position === null && $position !== null) {
                $this->table('aleph_book_authors')->where('id', $row->id)->update([
                    'position' => $position,
                    'updated_at' => Carbon::now(),
                ]);
            }

            return $this->bookAuthor((string) $row->id);
        });
    }

    /**
     * @param  list<SourceIdentifier>  $identifiers
     */
    public function upsertEdition(
        SourceIdentifier $sourceIdentifier,
        BookId $bookId,
        ?string $title = null,
        ?string $language = null,
        ?string $publisher = null,
        ?string $publishedAt = null,
        array $identifiers = [],
    ): Edition {
        /** @var Edition */
        return $this->connection->transaction(function () use ($sourceIdentifier, $bookId, $title, $language, $publisher, $publishedAt, $identifiers): Edition {
            $row = $this->sourceRow('aleph_editions', $sourceIdentifier);
            $fields = [
                'title' => $this->optional($title),
                'language' => $this->optional($language),
                'publisher' => $this->optional($publisher),
                'published_at' => $this->optional($publishedAt),
            ];

            if ($row instanceof stdClass) {
                $this->assertSame('edition', (string) $row->id, 'book_id', $row->book_id, $bookId->value);
                $this->enrichSourceRow('aleph_editions', $row, $fields, $sourceIdentifier, $identifiers);

                return $this->edition((string) $row->id);
            }

            $id = $this->insertSourceRow('aleph_editions', $sourceIdentifier, [
                'book_id' => $bookId->value,
                ...$fields,
            ], $identifiers);

            return $this->edition($id);
        });
    }

    /**
     * @param  list<SourceIdentifier>  $sourceIdentifiers
     * @param  array<string, mixed>  $acquisitionMetadata
     */
    public function upsertBookFile(
        EditionId $editionId,
        ResourceId $resourceId,
        ContentIdentity $contentIdentity,
        string $mimeType,
        ?string $format = null,
        ?string $encoding = null,
        array $sourceIdentifiers = [],
        array $acquisitionMetadata = [],
        ?DateTimeImmutable $acquiredAt = null,
        ?BookFileId $derivedFromFileId = null,
    ): BookFile {
        $mimeType = strtolower(trim($mimeType));
        $format = $this->optionalLower($format);
        $encoding = $this->optionalLower($encoding);

        if ($mimeType === '') {
            throw new InvalidArgumentException('A book file requires a MIME type.');
        }

        /** @var BookFile */
        return $this->connection->transaction(function () use ($editionId, $resourceId, $contentIdentity, $mimeType, $format, $encoding, $sourceIdentifiers, $acquisitionMetadata, $acquiredAt, $derivedFromFileId): BookFile {
            $key = $contentIdentity->key();
            $row = $this->table('aleph_book_files')->where('identity_key', $key)->first();
            $immutable = [
                'edition_id' => $editionId->value,
                'resource_id' => $resourceId->value,
                'mime_type' => $mimeType,
                'format' => $this->optional($format),
                'encoding' => $this->optional($encoding),
                'byte_size' => $contentIdentity->byteSize,
                'derived_from_file_id' => $derivedFromFileId?->value,
            ];

            if ($row instanceof stdClass) {
                if ($derivedFromFileId?->value === (string) $row->id) {
                    throw new InvalidArgumentException('A book file cannot derive from itself.');
                }

                foreach ($immutable as $field => $value) {
                    $this->assertSame('book_file', (string) $row->id, $field, $row->{$field}, $value);
                }

                $updates = [];
                $existingHashes = $this->decodeArray($row->hashes);
                $mergedHashes = $this->mergeHashes((string) $row->id, $existingHashes, $contentIdentity->hashes);
                $mergedIdentifiers = $this->mergeIdentifiers($this->decodeIdentifiers($row->source_identifiers), $sourceIdentifiers);
                $mergedMetadata = $this->fillArray($this->decodeArray($row->acquisition_metadata), $acquisitionMetadata);

                if ($mergedHashes !== $existingHashes) {
                    $updates['hashes'] = json_encode($mergedHashes, JSON_THROW_ON_ERROR);
                }

                if ($mergedIdentifiers !== $this->decodeIdentifiers($row->source_identifiers)) {
                    $updates['source_identifiers'] = $this->encodeIdentifierList($mergedIdentifiers);
                }

                if ($mergedMetadata !== $this->decodeArray($row->acquisition_metadata)) {
                    $updates['acquisition_metadata'] = json_encode($mergedMetadata, JSON_THROW_ON_ERROR);
                }

                if ($row->acquired_at === null && $acquiredAt !== null) {
                    $updates['acquired_at'] = $acquiredAt;
                }

                $this->updateIfNeeded('aleph_book_files', (string) $row->id, $updates);

                return $this->bookFile((string) $row->id);
            }

            $now = Carbon::now();
            $id = (string) Str::ulid();

            if ($derivedFromFileId?->value === $id) {
                throw new InvalidArgumentException('A book file cannot derive from itself.');
            }

            $this->table('aleph_book_files')->insert([
                'id' => $id,
                'identity_key' => $key,
                ...$immutable,
                'hashes' => json_encode($contentIdentity->hashes, JSON_THROW_ON_ERROR),
                'source_identifiers' => $this->encodeIdentifierList($this->mergeIdentifiers([], $sourceIdentifiers)),
                'acquisition_metadata' => json_encode($this->normalizeArray($acquisitionMetadata), JSON_THROW_ON_ERROR),
                'acquired_at' => $acquiredAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->bookFile($id);
        });
    }

    public function resource(ResourceId|string $id): Resource
    {
        $row = $this->find('aleph_resources', $id);

        return new Resource(
            new ResourceId((string) $row->id),
            $this->rowSourceIdentifier($row),
            (string) $row->resource_type,
            $row->canonical_uri === null ? null : (string) $row->canonical_uri,
            $row->language === null ? null : (string) $row->language,
            $this->decodeArray($row->metadata),
            $this->decodeIdentifiers($row->identifiers),
            $this->date($row->created_at),
            $this->date($row->updated_at),
        );
    }

    public function author(AuthorId|string $id): Author
    {
        $row = $this->find('aleph_authors', $id);

        return new Author(
            new AuthorId((string) $row->id),
            $this->rowSourceIdentifier($row),
            $row->name === null ? null : (string) $row->name,
            $this->decodeIdentifiers($row->identifiers),
            $this->date($row->created_at),
            $this->date($row->updated_at),
        );
    }

    public function book(BookId|string $id): Book
    {
        $row = $this->find('aleph_books', $id);

        return new Book(
            new BookId((string) $row->id),
            $this->rowSourceIdentifier($row),
            $row->title === null ? null : (string) $row->title,
            $row->language === null ? null : (string) $row->language,
            $this->decodeIdentifiers($row->identifiers),
            $this->date($row->created_at),
            $this->date($row->updated_at),
        );
    }

    public function edition(EditionId|string $id): Edition
    {
        $row = $this->find('aleph_editions', $id);

        return new Edition(
            new EditionId((string) $row->id),
            new BookId((string) $row->book_id),
            $this->rowSourceIdentifier($row),
            $row->title === null ? null : (string) $row->title,
            $row->language === null ? null : (string) $row->language,
            $row->publisher === null ? null : (string) $row->publisher,
            $row->published_at === null ? null : (string) $row->published_at,
            $this->decodeIdentifiers($row->identifiers),
            $this->date($row->created_at),
            $this->date($row->updated_at),
        );
    }

    public function bookFile(BookFileId|string $id): BookFile
    {
        $row = $this->find('aleph_book_files', $id);

        return new BookFile(
            new BookFileId((string) $row->id),
            new EditionId((string) $row->edition_id),
            new ResourceId((string) $row->resource_id),
            new ContentIdentity($this->decodeArray($row->hashes), $row->byte_size === null ? null : (int) $row->byte_size),
            (string) $row->mime_type,
            $row->format === null ? null : (string) $row->format,
            $row->encoding === null ? null : (string) $row->encoding,
            $this->decodeIdentifiers($row->source_identifiers),
            $this->decodeArray($row->acquisition_metadata),
            $row->acquired_at === null ? null : $this->date($row->acquired_at),
            $row->derived_from_file_id === null ? null : new BookFileId((string) $row->derived_from_file_id),
            $this->date($row->created_at),
            $this->date($row->updated_at),
        );
    }

    public function bookAuthor(BookAuthorId|string $id): BookAuthor
    {
        $row = $this->find('aleph_book_authors', $id);

        return new BookAuthor(
            new BookAuthorId((string) $row->id),
            new BookId((string) $row->book_id),
            new AuthorId((string) $row->author_id),
            (string) $row->role,
            $row->position === null ? null : (int) $row->position,
            $this->date($row->created_at),
            $this->date($row->updated_at),
        );
    }

    /**
     * @return list<BookAuthor>
     */
    public function creatorsFor(BookId $bookId): array
    {
        return array_values(array_map(
            fn (stdClass $row): BookAuthor => $this->bookAuthor((string) $row->id),
            $this->table('aleph_book_authors')
                ->where('book_id', $bookId->value)
                ->orderByRaw('position is null')
                ->orderBy('position')
                ->orderBy('role')
                ->orderBy('id')
                ->get()
                ->all(),
        ));
    }

    private function sourceRow(string $table, SourceIdentifier $identifier): ?stdClass
    {
        $row = $this->table($table)->where('identity_key', $identifier->key())->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<SourceIdentifier>  $identifiers
     */
    private function insertSourceRow(string $table, SourceIdentifier $sourceIdentifier, array $fields, array $identifiers): string
    {
        $now = Carbon::now();
        $id = (string) Str::ulid();
        $this->table($table)->insert([
            'id' => $id,
            'identity_key' => $sourceIdentifier->key(),
            'source' => $sourceIdentifier->source,
            'source_identifier' => $sourceIdentifier->identifier,
            ...$fields,
            'identifiers' => $this->encodeIdentifiers($sourceIdentifier, $identifiers),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param  array<string, ?string>  $fields
     * @param  list<SourceIdentifier>  $identifiers
     */
    private function enrichSourceRow(string $table, stdClass $row, array $fields, SourceIdentifier $sourceIdentifier, array $identifiers): void
    {
        $updates = [];

        foreach ($fields as $field => $value) {
            if ($row->{$field} === null && $value !== null) {
                $updates[$field] = $value;
            }
        }

        $merged = $this->mergeIdentifiers($this->decodeIdentifiers($row->identifiers), [$sourceIdentifier, ...$identifiers]);

        if ($merged !== $this->decodeIdentifiers($row->identifiers)) {
            $updates['identifiers'] = $this->encodeIdentifierList($merged);
        }

        $this->updateIfNeeded($table, (string) $row->id, $updates);
    }

    /**
     * @param  list<SourceIdentifier>  $identifiers
     */
    private function encodeIdentifiers(SourceIdentifier $primary, array $identifiers): string
    {
        return $this->encodeIdentifierList($this->mergeIdentifiers([], [$primary, ...$identifiers]));
    }

    /**
     * @param  list<SourceIdentifier>  $identifiers
     */
    private function encodeIdentifierList(array $identifiers): string
    {
        return json_encode(
            array_map(static fn (SourceIdentifier $identifier): array => $identifier->toArray(), $identifiers),
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  list<SourceIdentifier>  $existing
     * @param  list<SourceIdentifier>  $incoming
     * @return list<SourceIdentifier>
     */
    private function mergeIdentifiers(array $existing, array $incoming): array
    {
        $merged = [];

        foreach ([$existing, $incoming] as $identifiers) {
            foreach ($identifiers as $identifier) {
                if (! $identifier instanceof SourceIdentifier) {
                    throw new InvalidArgumentException('Identifiers must be SourceIdentifier values.');
                }

                $key = $identifier->source."\0".$identifier->identifier;
                $merged[$key] ??= $identifier;
            }
        }

        ksort($merged, SORT_STRING);

        return array_values($merged);
    }

    /**
     * @return list<SourceIdentifier>
     */
    private function decodeIdentifiers(mixed $encoded): array
    {
        return array_values(array_map(
            static fn (array $identifier): SourceIdentifier => new SourceIdentifier(
                (string) $identifier['source'],
                (string) $identifier['identifier'],
            ),
            $this->decodeArray($encoded),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArray(mixed $encoded): array
    {
        if (is_array($encoded)) {
            return $encoded;
        }

        $value = json_decode((string) $encoded, true, 512, JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, string>  $incoming
     * @return array<string, string>
     */
    private function mergeHashes(string $fileId, array $existing, array $incoming): array
    {
        foreach ($incoming as $algorithm => $digest) {
            if (isset($existing[$algorithm]) && $existing[$algorithm] !== $digest) {
                throw ImmutableBibliographicConflict::forField('book_file', $fileId, 'hashes.'.$algorithm);
            }

            $existing[$algorithm] = $digest;
        }

        ksort($existing, SORT_STRING);

        /** @var array<string, string> $existing */
        return $existing;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function fillArray(array $existing, array $incoming): array
    {
        if ($existing === []) {
            return $this->normalizeArray($incoming);
        }

        if (array_is_list($existing) || array_is_list($incoming)) {
            return $existing;
        }

        foreach ($incoming as $key => $value) {
            if (! array_key_exists($key, $existing)) {
                $existing[$key] = is_array($value) ? $this->normalizeArray($value) : $value;
            } elseif (is_array($existing[$key]) && is_array($value)) {
                $existing[$key] = $this->fillArray($existing[$key], $value);
            }
        }

        ksort($existing, SORT_STRING);

        return $existing;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalizeArray($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function updateIfNeeded(string $table, string $id, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $this->table($table)->where('id', $id)->update([
            ...$updates,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function assertSame(string $entity, string $identity, string $field, mixed $existing, mixed $incoming): void
    {
        if ($existing !== $incoming) {
            throw ImmutableBibliographicConflict::forField($entity, $identity, $field);
        }
    }

    private function find(string $table, BibliographicId|string $id): stdClass
    {
        return $this->table($table)->where('id', (string) $id)->firstOrFail();
    }

    private function rowSourceIdentifier(stdClass $row): SourceIdentifier
    {
        return new SourceIdentifier((string) $row->source, (string) $row->source_identifier);
    }

    private function optional(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function optionalLower(?string $value): ?string
    {
        $value = $this->optional($value);

        return $value === null ? null : strtolower($value);
    }

    private function date(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value);
    }

    private function table(string $table): Builder
    {
        return $this->connection->table($table);
    }
}
