# Aleph

> **License:** Copyright © 2026 Sifrious. All rights reserved. This is
> publicly viewable proprietary software, not open-source software. See
> [LICENSE.md](LICENSE.md).

Aleph is a Laravel package for bounded ingestion from external sources. Aleph keeps operational state for discovery, configuration, scheduling, retries, checkpoints, and health. Historical records cross an explicit acceptance boundary into Funes.

The package currently includes ingestion for public websites, Git and GitHub, Linear, email, Slack, Discord, Telegram, SMS and MMS, AI conversations, and shell history. It also has launch adapters for YouTube, podcasts, Apple Mail, Google Drive, images, handwriting, MIDI, scores and tabs, spoken sound, local video, and NativePHP desktop submissions. See [`docs/connectors.md`](docs/connectors.md) for the connector contracts and ownership boundaries.

Provider claims cross that boundary through `HistoricalAssertionAdapter`. A host tags adapters as
`aleph.historical_assertion_adapters`, and `HistoricalAssertionAdapters` selects one by its stable
provider name. Each adapter returns a canonical observed, declared, or inferred Funes assertion plus
the provider fields it omitted and optional confidence. `HistoricalAssertionAcceptance` appends the
claim with the caller's authorization context. The raw provider record remains a portable provenance
reference rather than canonical Funes state.

## Installation

Install Aleph and run the package migrations:

```bash
composer require sifrious/aleph
php artisan migrate
```

Aleph depends on `sifrious/funes`. Laravel package discovery registers both service providers and loads both packages' migrations.

Landing is the master application schema. Where Aleph names a concept the master schema already names,
the name comes from the landing model-attribute ownership matrix: ingestion runs use `source_reference`,
`capability`, `status`, `parameters`, `stats`, `error`, `started_at`, and `finished_at`; observation
facts use `observed_at`, `ingested_at`, `payload_hash`, and `byte_size`; extraction facts carried onto
another table use `extraction_status` and `extraction_error`. Package migrations add columns; they do
not introduce a second spelling for a concept the master schema already names.

A source is referenced as `web:<key>` — `web:ahsd` — in both `aleph_ingestion_runs.source_reference`
and `funes_sources.reference`, so a run joins its Funes source by equality. The CLI argument and the
configuration key stay the bare `ahsd`.

## Ingestion contract

An ingestion run records a source reference, one declared capability, input parameters, lifecycle status, deterministic stats, and an optional error. Connector manifests derive their supported capabilities from the small contracts listed in [`docs/connectors.md`](docs/connectors.md); callers must not assume that every connector supports every operation.

Landing is the master application schema and the source of truth for shared database vocabulary. Aleph's migrations append only `aleph_*` operational tables; overlapping run fields use landing's `stats` and `error` names.

Runs move through these implemented states:

```text
running -> completed
running -> interrupted -> running
```

An interrupted run is resumed by default. `--fresh` starts another run. A run cannot complete while a required Funes submission has failed.

## Operating Aleph

The package exposes its connector catalogue and durable run controls through Artisan:

```bash
php artisan aleph:connectors
php artisan aleph:connectors --json
php artisan aleph:source:configure web-crawl ahsd "AHSD" \
    --value='seeds=["https://www.ahsd.org/"]' \
    --value='allowed_hosts=["ahsd.org","*.ahsd.org"]'
php artisan aleph:runs
php artisan aleph:run:show <run-id> --json
```

`aleph:run` creates a durable, authorized manual run. The host must bind
`ManualIngestionDispatcher` to send that run to its worker system. `aleph:run:retry` and
`aleph:run:resume` use the host's `IngestionQueue` binding. Aleph refuses unsupported capabilities,
disabled installations, denied authorization, unsafe retries, and unbounded resume requests before
dispatch.

Source values and run parameters use repeatable `key=value` options. JSON values decode as their
native type. Credentials are never accepted as values. Pass only an opaque host-owned reference with
`--credential-reference`.

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
    'calendar_signals' => ['*calendar', '*calendar/*'],
    'limits' => [
        'max_pages' => 200,
        'max_depth' => 3,
    ],
],
```

An empty `query_parameters` list drops all query parameters during canonicalization. Named parameters are retained and sorted.

`calendar_signals` are canonical-path patterns that mark a resource calendar-like in the inventory.
They are configuration rather than code because they are evidence: every pattern configured for
`ahsd` traces to a captured request in [`docs/investigations/schoolmessenger.md`](docs/investigations/schoolmessenger.md).

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

A retrieved response and its versioned mechanical extraction must be accepted by Funes before its frontier candidate becomes `fetched`. Aleph sends the canonical final URL, raw response bytes, status, content type, requested and final URLs, redirect chain, retrieval time, run reference, discovery origin, and typed canonical discoveries.

Funes returns the accepted observation and the recorded extraction. Aleph keeps what that boundary
handed back — observation reference, disposition, payload hash, byte size, ingestion time, and the
versioned extraction outcome — beside its own operational attempt state. It neither reads Funes'
tables nor recomputes the hash.

Funes returns `first`, `unchanged`, or `changed`. Aleph stores only the stable observation reference and disposition beside the operational attempt state. It does not store response bodies. Identical fresh crawls create no duplicate historical effect; changed bytes create another immutable Funes observation. Failed Funes observation or extraction persistence interrupts the run with its candidate still resumable.

## Mechanical extraction

Retrieved observations are classified by normalized content type as HTML, PDF, or unsupported. The selector records the chosen extractor name and version with every Funes extraction result.

The HTML extractor records normalized document text and discovers anchor links, iframe sources, embed sources, and object data. Their `link`, `iframe`, or `embed` relationship survives canonicalization into Funes discovery provenance. Allowed-host discoveries can enter the frontier; external discoveries are retained as skipped evidence and can be resolved back to their parent observation with `ObservationStore::discoveriesTo()`, but can never be claimed for retrieval.

The PDF extractor preserves the original response bytes as the Funes observation payload and records text extracted from the embedded PDF text layer. Image-only PDFs therefore remain preserved even when they yield no text; OCR is outside this mechanical layer. Unsupported content is preserved and classified without inventing an extraction.

## Current boundary

Mechanical extraction does not execute scripts, submit forms, crawl external hosts, perform OCR, or interpret calendars. Provider-specific calendar transport remains a separate capability.

## The AHSD inventory

```bash
php artisan aleph:inventory ahsd --json=ahsd.json --csv=ahsd.csv
```

The command inventories the named run (`--run=`) or the latest run for the source, applying the crawl
parameters that run actually recorded rather than current configuration. A named run must belong to
the named source; otherwise the run's recorded bounds and the source's configured host policy would
describe different crawls in one document. Without a path option it prints totals only.

An inventory is derived on read and stores nothing. It carries the run's bounds — capability, status,
start and finish, page and depth limits, seeds, allowed hosts, host restrictions, exclusions, query
allowlist, calendar signals, deterministic stats, and error — and one row per frontier candidate:

| Group | Columns |
| --- | --- |
| Identity | `canonical_url`, `canonical_hash`, `requested_url`, `final_url`, `host`, `depth` |
| Provenance | `origin`, `parent_canonical_url`, `external` |
| Frontier | `state`, `skip_reason` |
| HTTP result | `http_status`, `content_type`, `failure`, `failure_message` |
| Observation | `observation_id`, `observation_disposition`, `payload_hash`, `byte_size`, `observed_at`, `ingested_at`, `last_observed_at` |
| Extraction | `extractor`, `extraction_version`, `extraction_status`, `extraction_error` |
| Classification | `calendar_like`, `calendar_signal`, `freshness` |

HTTP and extraction columns always describe this run. Observation columns describe the most recent
content evidence for the canonical resource, and `freshness` says where it came from: `current` if
this run produced it, `stale` if only an earlier run did, `unobserved` if none exists. A page-limited
run therefore reports what it did not reach without pretending it was never seen.

A resource is calendar-like either because its canonical path matches one of the source's configured
`calendar_signals` or because it was discovered as an `iframe` or `embed` of a page that is. The
second signal is what carries the Waverly artifact: a Google Drive URL with nothing calendrical in it,
external, never retrieved, and calendar-like only because of the edge that reaches it.

Both exports are deterministic. Column order is fixed, rows are sorted by canonical URL in PHP rather
than by database collation, instants are UTC, and no generation timestamp is embedded, so the same
crawl state produces byte-identical files and two runs can be diffed.

## Investigating a source

Third-party platforms are investigated only through requests that were actually issued and actually
returned. Identifiers found in markup are not a licence to construct an endpoint, and a negative
result is a result: `docs/investigations/schoolmessenger.md` records the captured requests behind
every claim Aleph makes about AHSD, including why SchoolMessenger calendar event data is out of reach
rather than merely unbuilt.

The current decision, assumption, question, glossary, and structured project-memory records are in
[`docs/`](docs/).
