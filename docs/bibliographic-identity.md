# Bibliographic identity

Aleph's bibliographic catalog separates four identities:

| Layer | Identity | Meaning |
| --- | --- | --- |
| `resource` | source namespace + opaque source identifier | Generic source locator or record |
| `book` | source namespace + opaque source identifier | Conceptual work |
| `edition` | source namespace + opaque source identifier | Particular textual or published version of a work |
| `book_file` | SHA-256 digest | Exact acquired or derived bytes |

`author` uses the same source identity rule. `book_author` is identified by book, author, and
normalized role, allowing one person to have several roles and preserving optional creator order.
Every persisted primary key and foreign key is a ULID exposed through a distinct PHP value type.

The catalog API is `Sifrious\Aleph\Bibliography\BibliographicCatalog`. Its writes are
`upsertResource`, `upsertAuthor`, `upsertBook`, `attachAuthor`, `upsertEdition`, and
`upsertBookFile`. It returns immutable `Resource`, `Author`, `Book`, `BookAuthor`, `Edition`, and
`BookFile` values. Connectors should pass the published ID types across package seams instead of
table names or untyped strings.

## Reconciliation

Source namespaces are trimmed and lowercased. Source identifiers are opaque and remain
case- and whitespace-sensitive. An exact source identity returns its existing resource, author,
book, or edition. Replaying unchanged evidence does not update timestamps.

Later evidence may fill a null descriptive value, add a source identifier, or add a previously
unknown metadata key. It does not replace an existing value. Resource type, a resource's established
canonical URI, edition-to-book linkage, creator order, and file linkage are immutable; conflicting
evidence raises `ImmutableBibliographicConflict`. Titles and creator names never participate in
identity or automatic reconciliation.

Secondary source identifiers are preserved as evidence. The identity passed as the first argument is
the reconciliation anchor; callers must continue using that primary identity on retries. Promoting
aliases to independently addressable reconciliation keys requires a separately indexed alias model
and is intentionally outside this six-table Wave 1 contract.

## Content identity and acquisition

`ContentIdentity` requires SHA-256. The lowercased SHA-256 digest is the global `book_file`
identity; adding MD5 or another corroborating hash does not change it. A replay may add a missing
secondary hash, source identifier, acquisition timestamp, or acquisition metadata key. Equal
SHA-256 content must agree on edition, resource, byte size, MIME type, format, encoding, and
`derived_from_file_id`, otherwise reconciliation fails.

`book_file` stores identity and acquisition evidence, not bytes. Each file points to an edition and
to the generic resource from which it was acquired. A transformed artifact is a separate file with
its own content identity and an optional immutable `derived_from_file_id`; derivation chains never
turn the source file or its edition into the conceptual book.

Funes remains the immutable history boundary for what a source said and when. This catalog is the
current canonical index used to reconcile explicit bibliographic and artifact identities.
