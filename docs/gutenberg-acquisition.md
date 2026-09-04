# Project Gutenberg acquisition boundary

`GutenbergConnector` implements Aleph's generic `DiscoversSources` and
`DownloadsArtifacts` contracts. It discovers official RDF metadata, selects only a
file URL listed by that metadata, and preserves both metadata and downloaded bytes.
It does not create books, editions, authors, or interpreted text.

## Provisional identity assumptions

MME-3814 is being built in parallel with MME-3813. Until canonical identity types
land, this adapter uses `gutenberg:ebook/{positive-id}` as a source-side reference
and `sha256:{digest}` as byte content identity. Both are marked provisional in
returned metadata. They are integration seams, not a competing bibliographic model.

Final integration must replace or map these references to MME-3813 resource and
book-file identities without changing preserved artifacts or acquisition manifests.

## Acquisition policy

- Metadata comes from the official `/ebooks/{id}.rdf` endpoint. The exact RDF bytes
  are retained by checksum alongside a parsed acquisition record.
- Candidate URLs are taken from RDF; the adapter never guesses download URLs.
- Selection is deterministic: UTF-8 plain text, other plain text, image-free EPUB,
  EPUB, then other formats, with URL as the tie-breaker.
- Original bytes are written to an immutable SHA-256 blob path. URL-keyed manifests
  retain first acquisition time and HTTP validators. A verified cache hit performs
  no network request and preserves the first acquisition time.
- Encoding and Project Gutenberg header/footer byte offsets are evidence only.
  Nothing is stripped, transcoded, or otherwise interpreted.
- Connection errors, 429 responses, and 5xx responses receive bounded retries.
  Other HTTP failures are reported immediately.
