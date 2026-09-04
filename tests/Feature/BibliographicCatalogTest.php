<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sifrious\Aleph\Bibliography\BibliographicCatalog;
use Sifrious\Aleph\Bibliography\Book;
use Sifrious\Aleph\Bibliography\ContentIdentity;
use Sifrious\Aleph\Bibliography\Edition;
use Sifrious\Aleph\Bibliography\ImmutableBibliographicConflict;
use Sifrious\Aleph\Bibliography\SourceIdentifier;

function bibliography(): BibliographicCatalog
{
    return app(BibliographicCatalog::class);
}

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{0: Book, 1: Edition, 2: Sifrious\Aleph\Bibliography\Resource}
 */
function bibliographicFixture(string $suffix = 'one'): array
{
    $catalog = bibliography();
    $book = $catalog->upsertBook(new SourceIdentifier('catalog', 'book-'.$suffix), 'A Book', 'en');
    $edition = $catalog->upsertEdition(new SourceIdentifier('catalog', 'edition-'.$suffix), $book->id, publisher: 'A Press');
    $resource = $catalog->upsertResource(new SourceIdentifier('catalog', 'resource-'.$suffix), 'https://books.test/'.$suffix);

    return [$book, $edition, $resource];
}

it('creates the six catalog tables with enforced foreign keys', function (): void {
    expect(Schema::hasColumns('aleph_resources', ['id', 'identity_key', 'resource_type', 'canonical_uri', 'language', 'metadata']))->toBeTrue()
        ->and(Schema::hasColumns('aleph_authors', ['id', 'identity_key', 'name']))->toBeTrue()
        ->and(Schema::hasColumns('aleph_books', ['id', 'identity_key', 'title']))->toBeTrue()
        ->and(Schema::hasColumns('aleph_book_authors', ['book_id', 'author_id', 'role']))->toBeTrue()
        ->and(Schema::hasColumns('aleph_editions', ['book_id', 'identity_key']))->toBeTrue()
        ->and(Schema::hasColumns('aleph_book_files', ['edition_id', 'resource_id', 'derived_from_file_id']))->toBeTrue();

    $editionForeignTables = array_map(
        static fn (object $foreignKey): string => (string) $foreignKey->table,
        DB::select("PRAGMA foreign_key_list('aleph_editions')"),
    );

    expect($editionForeignTables)->toContain('aleph_books');
});

it('keeps conceptual books separate from editions and acquired files', function (): void {
    [$book, $edition, $resource] = bibliographicFixture();
    $file = bibliography()->upsertBookFile(
        $edition->id,
        $resource->id,
        ContentIdentity::sha256(hash('sha256', 'book bytes'), 10),
        'application/epub+zip',
        'epub',
        sourceIdentifiers: [new SourceIdentifier('catalog', 'file-one')],
    );

    expect($edition->bookId)->toEqual($book->id)
        ->and($file->editionId)->toEqual($edition->id)
        ->and($file->resourceId)->toEqual($resource->id)
        ->and(DB::table('aleph_books')->count())->toBe(1)
        ->and(DB::table('aleph_editions')->count())->toBe(1)
        ->and(DB::table('aleph_book_files')->count())->toBe(1);
});

it('supports multiple creators and roles without collapsing them', function (): void {
    [$book] = bibliographicFixture();
    $author = bibliography()->upsertAuthor(new SourceIdentifier('catalog', 'author-one'), 'Mary Author');
    $illustrator = bibliography()->upsertAuthor(new SourceIdentifier('catalog', 'author-two'), 'Ira Illustrator');

    bibliography()->attachAuthor($book->id, $author->id, 'author', 1);
    bibliography()->attachAuthor($book->id, $author->id, 'translator', 2);
    bibliography()->attachAuthor($book->id, $illustrator->id, 'illustrator', 3);

    expect(bibliography()->creatorsFor($book->id))->toHaveCount(3)
        ->and(array_map(static fn ($creator): string => $creator->role, bibliography()->creatorsFor($book->id)))
        ->toBe(['author', 'translator', 'illustrator']);
});

it('normalizes creator roles and reconciles positions explicitly', function (): void {
    [$book] = bibliographicFixture();
    $author = bibliography()->upsertAuthor(new SourceIdentifier('catalog', 'positioned-author'));
    Carbon::setTestNow('2026-09-04 10:00:00');
    $first = bibliography()->attachAuthor($book->id, $author->id, ' Editor ');
    Carbon::setTestNow('2026-09-04 11:00:00');
    $filled = bibliography()->attachAuthor($book->id, $author->id, 'EDITOR', 2);
    Carbon::setTestNow('2026-09-04 12:00:00');
    $replayed = bibliography()->attachAuthor($book->id, $author->id, 'editor', 2);

    expect($filled->id)->toEqual($first->id)
        ->and($filled->role)->toBe('editor')
        ->and($filled->position)->toBe(2)
        ->and($replayed->updatedAt)->toEqual($filled->updatedAt)
        ->and(fn () => bibliography()->attachAuthor($book->id, $author->id, 'editor', 3))
        ->toThrow(ImmutableBibliographicConflict::class);
});

it('normalizes only the source namespace and preserves opaque identifiers', function (): void {
    $identifier = new SourceIdentifier(' Open-Library ', '  OL1W/Case-Sensitive  ');
    $first = bibliography()->upsertAuthor(new SourceIdentifier('Open-Library', 'OL1W'));
    $replayed = bibliography()->upsertAuthor(new SourceIdentifier('open-library', 'OL1W'));

    expect($identifier->source)->toBe('open-library')
        ->and($identifier->identifier)->toBe('  OL1W/Case-Sensitive  ')
        ->and((string) $identifier)->toBe('open-library:  OL1W/Case-Sensitive  ')
        ->and($replayed->id)->toEqual($first->id);
});

it('replays explicit source identity without changing identity or timestamps', function (): void {
    Carbon::setTestNow('2026-09-04 10:00:00');
    $first = bibliography()->upsertBook(new SourceIdentifier('open-library', 'OL1W'), 'Replay');
    Carbon::setTestNow('2026-09-04 11:00:00');
    $replayed = bibliography()->upsertBook(new SourceIdentifier('open-library', 'OL1W'), 'Replay');

    expect($replayed->id)->toEqual($first->id)
        ->and($replayed->createdAt)->toEqual($first->createdAt)
        ->and($replayed->updatedAt)->toEqual($first->updatedAt)
        ->and(DB::table('aleph_books')->count())->toBe(1);
});

it('safely enriches missing descriptions and merges explicit identifiers', function (): void {
    Carbon::setTestNow('2026-09-04 10:00:00');
    $first = bibliography()->upsertAuthor(new SourceIdentifier('archive', 'person-1'));
    Carbon::setTestNow('2026-09-04 11:00:00');
    $enriched = bibliography()->upsertAuthor(
        new SourceIdentifier('archive', 'person-1'),
        'A. Writer',
        [new SourceIdentifier('isni', '0000000123456789')],
    );

    expect($first->name)->toBeNull()
        ->and($enriched->name)->toBe('A. Writer')
        ->and(array_map(strval(...), $enriched->identifiers))
        ->toBe(['archive:person-1', 'isni:0000000123456789'])
        ->and($enriched->updatedAt)->not->toEqual($first->updatedAt);
});

it('represents generic resources and safely enriches optional locator metadata', function (): void {
    Carbon::setTestNow('2026-09-04 10:00:00');
    $first = bibliography()->upsertResource(
        new SourceIdentifier('archive', 'source-1'),
        resourceType: 'archive-record',
    );
    Carbon::setTestNow('2026-09-04 11:00:00');
    $enriched = bibliography()->upsertResource(
        new SourceIdentifier('archive', 'source-1'),
        canonicalUri: 'urn:archive:source-1',
        resourceType: 'archive-record',
        language: 'en',
        metadata: ['collection' => 'rare-books'],
    );
    $replayed = bibliography()->upsertResource(
        new SourceIdentifier('archive', 'source-1'),
        canonicalUri: null,
        resourceType: 'archive-record',
    );

    expect($first->canonicalUri)->toBeNull()
        ->and($enriched->canonicalUri)->toBe('urn:archive:source-1')
        ->and($enriched->language)->toBe('en')
        ->and($enriched->metadata)->toBe(['collection' => 'rare-books'])
        ->and($replayed->updatedAt)->toEqual($enriched->updatedAt)
        ->and(fn () => bibliography()->upsertResource(
            new SourceIdentifier('archive', 'source-1'),
            canonicalUri: 'urn:archive:different',
            resourceType: 'archive-record',
        ))->toThrow(ImmutableBibliographicConflict::class)
        ->and(fn () => bibliography()->upsertResource(
            new SourceIdentifier('archive', 'source-1'),
            resourceType: 'web',
        ))->toThrow(ImmutableBibliographicConflict::class);
});

it('throws on conflicting immutable source identity and linkage', function (): void {
    [$book, $edition] = bibliographicFixture();
    $otherBook = bibliography()->upsertBook(new SourceIdentifier('catalog', 'book-two'));

    expect(fn () => bibliography()->upsertResource(
        new SourceIdentifier('catalog', 'resource-conflict'),
        'https://books.test/original',
    ))->not->toThrow(ImmutableBibliographicConflict::class);

    bibliography()->upsertResource(new SourceIdentifier('catalog', 'resource-stable'), 'https://books.test/stable');

    expect(fn () => bibliography()->upsertResource(
        new SourceIdentifier('catalog', 'resource-stable'),
        'https://books.test/different',
    ))->toThrow(ImmutableBibliographicConflict::class)
        ->and(fn () => bibliography()->upsertEdition(
            new SourceIdentifier('catalog', 'edition-one'),
            $otherBook->id,
        ))->toThrow(ImmutableBibliographicConflict::class);

    expect($edition->bookId)->toEqual($book->id);
});

it('deduplicates content globally and requires immutable file attributes to agree', function (): void {
    [, $edition, $resource] = bibliographicFixture();
    $identity = ContentIdentity::sha256(hash('sha256', 'same bytes'), 10);
    $first = bibliography()->upsertBookFile($edition->id, $resource->id, $identity, 'Text/Plain', 'TXT', 'UTF-8');
    $enrichedIdentity = new ContentIdentity([
        'md5' => hash('md5', 'same bytes'),
        'sha256' => hash('sha256', 'same bytes'),
    ], 10);
    $replayed = bibliography()->upsertBookFile(
        $edition->id,
        $resource->id,
        $enrichedIdentity,
        'text/plain',
        'txt',
        'utf-8',
        [new SourceIdentifier('mirror', 'file-22')],
    );

    expect($replayed->id)->toEqual($first->id)
        ->and([$replayed->mimeType, $replayed->format, $replayed->encoding])->toBe(['text/plain', 'txt', 'utf-8'])
        ->and($replayed->sourceIdentifiers)->toHaveCount(1)
        ->and($replayed->contentIdentity->hashes)->toBe($enrichedIdentity->hashes)
        ->and(DB::table('aleph_book_files')->count())->toBe(1)
        ->and(fn () => bibliography()->upsertBookFile(
            $edition->id,
            $resource->id,
            $identity,
            'application/octet-stream',
        ))->toThrow(ImmutableBibliographicConflict::class);
});

it('requires one exact canonical SHA-256 digest for content identity', function (): void {
    $digest = hash('sha256', 'identity');
    $shaOnly = new ContentIdentity(['sha256' => strtoupper($digest)]);
    $withPreservedHash = new ContentIdentity(['sha256' => $digest, 'md5' => hash('md5', 'identity')]);

    expect($shaOnly->key())->toBe($digest)
        ->and($withPreservedHash->key())->toBe($digest)
        ->and(fn () => new ContentIdentity(['md5' => hash('md5', 'identity')]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ContentIdentity(['sha256' => str_repeat('g', 64)]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ContentIdentity(['sha256' => str_repeat('a', 63)]))
        ->toThrow(InvalidArgumentException::class);
});

it('records original and derived file relationships', function (): void {
    [, $edition, $resource] = bibliographicFixture();
    $derivedResource = bibliography()->upsertResource(
        new SourceIdentifier('catalog', 'resource-derived'),
        'https://books.test/derived',
    );
    $original = bibliography()->upsertBookFile(
        $edition->id,
        $resource->id,
        ContentIdentity::sha256(hash('sha256', 'scan')),
        'application/pdf',
        acquiredAt: new DateTimeImmutable('2026-09-04T09:00:00Z'),
    );
    $derived = bibliography()->upsertBookFile(
        $edition->id,
        $derivedResource->id,
        ContentIdentity::sha256(hash('sha256', 'ocr text')),
        'text/plain',
        'txt',
        'utf-8',
        acquisitionMetadata: ['derivation' => ['method' => 'ocr']],
        derivedFromFileId: $original->id,
    );

    expect($derived->derivedFromFileId)->toEqual($original->id)
        ->and($derived->acquisitionMetadata)->toBe(['derivation' => ['method' => 'ocr']])
        ->and(fn () => bibliography()->upsertBookFile(
            $edition->id,
            $resource->id,
            $original->contentIdentity,
            'application/pdf',
            derivedFromFileId: $original->id,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => bibliography()->upsertBookFile(
            $edition->id,
            $derivedResource->id,
            $derived->contentIdentity,
            'text/plain',
            'txt',
            'utf-8',
            derivedFromFileId: null,
        ))->toThrow(ImmutableBibliographicConflict::class);
});
