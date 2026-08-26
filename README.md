# Aleph

Aleph is a Laravel package for bounded ingestion from external sources. Its implemented capability is a public-web crawl whose operational state remains in Aleph and whose retrieved content and discovery provenance are accepted by Funes.

## Installation

Install Aleph and run the package migrations:

```bash
composer require sifrious/aleph
php artisan migrate
```

Aleph depends on `sifrious/funes`. Laravel package discovery registers both service providers and loads both packages' migrations.

## Ingestion contract

An ingestion run records a source reference, one declared capability, input parameters, lifecycle status, deterministic totals, and an optional failure. The only capability currently admitted by `Capability` is `web.crawl`.

Runs move through these implemented states:

```text
running -> completed
running -> interrupted -> running
```

An interrupted run is resumed by default. `--fresh` starts another run. A run cannot complete while a required Funes submission has failed.

## Configuring a web source

Sources are declared under `aleph.web_sources`:

```php
'ahsd' => [
    'name' => 'Abington Heights School District',
    'seeds' => [
        'https://www.ahsd.org/',
        'https://hs.ahsd.org/',
        'https://ms.ahsd.org/',
        'https://cse.ahsd.org/',
        'https://wav.ahsd.org/',
    ],
    'allowed_hosts' => ['ahsd.org', '*.ahsd.org'],
    'excluded' => ['*/login*', '*/logout*', '*/account*'],
    'query_parameters' => [],
    'limits' => [
        'max_pages' => 200,
        'max_depth' => 3,
    ],
],
```

An empty `query_parameters` list drops all query parameters during canonicalization. Named parameters are retained and sorted.

## Running a crawl

```bash
php artisan aleph:crawl ahsd --max-pages=50 --max-depth=2
```

The command supports `--max-pages`, `--max-depth`, repeatable `--host`, and `--fresh`. It reports fetched responses, non-2xx responses, transport failures, skips by reason, duplicates, unresolvable references, discoveries, remaining candidates, and the stop reason.

The frontier is breadth-first and database-backed. Canonical URL identity lowercases scheme and host, removes credentials, default ports, fragments, and dot segments, and applies the configured query allowlist. A unique `(run_id, canonical_hash)` constraint prevents duplicate fetches. Page and depth limits bound traversal. External, excluded, and over-depth URLs remain recorded as skipped evidence and are never claimed for retrieval.

## HTTP policy

The production fetcher permits only GET and HEAD. It applies connect and request timeouts, a redirect ceiling, a response-size ceiling enforced against both `Content-Length` and the streamed body, a configurable per-host delay, one bounded transport retry by default, and `robots.txt` policy. A missing `robots.txt` permits crawling; an unreachable or server-error response refuses crawling for that host.

The crawler is sequential. Its effective concurrency ceiling is one. Non-2xx responses are observations; transport, size, redirect, and robots failures remain Aleph operational evidence and do not create successful Funes observations.

## Funes persistence

A retrieved response must be accepted by Funes before its frontier candidate becomes `fetched`. Aleph sends the canonical final URL, raw response bytes, status, content type, requested and final URLs, redirect chain, retrieval time, run reference, discovery origin, and canonical discovered URLs.

Funes returns `first`, `unchanged`, or `changed`. Aleph stores only the stable observation reference and disposition beside the operational attempt state. It does not store response bodies. Identical fresh crawls create no duplicate historical effect; changed bytes create another immutable Funes observation. A failed Funes acceptance interrupts the run with its candidate still resumable.

## Current boundary

`LinkSource` defaults to `NoLinks`, so the production command currently retrieves configured seeds only. Tests inject a deterministic link source to prove bounded frontier behavior and provenance. HTML parsing and embed classification are separate capabilities and are not implemented here.

The current decision, assumption, question, glossary, and structured project-memory records are in [`docs/`](docs/).
