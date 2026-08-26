# Decision register

Decisions taken while implementing ALEPH-005 (bounded named crawl) and ALEPH-006
(conservative HTTP fetching). Each entry records the decision, why, and what was rejected.

## D-001 — Capability and ingestion-run contracts stop at demonstrated behavior

**Decision.** `Capability` currently admits only `web.crawl`. `IngestionRun` records source,
capability, parameters, lifecycle, totals, and failure. Connector manifests, generic attempts, and
checkpoint contracts remain absent until another implemented capability requires them.

**Rationale.** ALEPH-005 requires an explicit bounded ingestion run and operation identity. It does
not require the remainder of ALEPH-001's provider-neutral connector model.

**Rejected.** Encoding hypothetical backfill, incremental-sync, checkpoint, approval, and parallel
work contracts before an executable connector uses them.

## D-002 — Funes acceptance is required before operational success

**Decision.** Aleph directly depends on Funes' `ObservationStore`. A retrieved candidate becomes
`fetched` only after acceptance returns a stable observation reference and disposition.

**Rationale.** This establishes one canonical content-history boundary. A submission failure leaves
the candidate resumable and interrupts the ingestion run, so the run cannot report false success.

**Rejected.** An Aleph-side writer abstraction that would duplicate the already demonstrated Funes
boundary, and storing response bodies in Aleph.

## D-003 — Query parameters are an allowlist, not a denylist

**Decision.** `UrlCanonicalizer` drops every query parameter not named in the source's
`query_parameters` list. An empty list drops all parameters.

**Rationale.** AHSD is calendar-heavy. Stripping known tracking parameters still leaves an unbounded
`?date=` space. An allowlist is what actually satisfies "query-string variants cannot create an
unbounded frontier"; `max_pages` is only the backstop.

**Rejected.** A denylist of `utm_*`, `fbclid`, and friends — bounded against marketing noise, useless
against a calendar.

## D-004 — Canonical identity is indexed by hash, not by URL text

**Decision.** `aleph_frontier_candidates` stores `canonical_url` as text and `canonical_hash`
(sha256) as `char(64)`, with the unique index on `(run_id, canonical_hash)`.

**Rationale.** A unique index on a text column needs a prefix length in MySQL and is not portable.
The index is what enforces "duplicate canonical URLs are fetched at most once per run" — in the
database, not in application logic. Funes independently arrived at the same shape in
`funes_resources`, so Funes identity receives the same stable representation.

## D-005 — `HttpMethod` admits only GET and HEAD

**Decision.** `FetchRequest` takes an `HttpMethod` enum whose only cases are `Get` and `Head`.

**Rationale.** ALEPH-006 requires that the crawler cannot submit forms or issue mutation requests.
An enum with no other cases makes that structurally impossible rather than policy-enforced.

**Rejected.** A string method with a validation guard, which a later edit could widen.

## D-006 — The robots subset is parsed in-package

**Decision.** `RobotsRules` parses the `User-agent` / `Disallow` / `Allow` / `Crawl-delay` subset,
including `*` and `$` patterns and longest-match precedence, in about 120 lines.

**Rationale.** The needed subset is small and stable. A dependency for it would carry transitive
cost and removal cost out of proportion to the work.

**Revisit if.** Aleph needs sitemap parsing or full RFC 9309 conformance.

## D-007 — An unreadable robots file means do not crawl

**Decision.** A robots.txt that returns 5xx or cannot be retrieved yields disallow-all for that host.
A 404 or other 4xx yields allow-all.

**Rationale.** ALEPH-006 asks for conservative behaviour. The refusal is visible: it surfaces as
`robots_disallowed` in the run's failure totals rather than as a silent skip.

**Rejected.** Failing open on transport errors, which would crawl a host that never granted permission.

## D-008 — Runs are ULIDs, candidates are auto-increment

**Decision.** `aleph_ingestion_runs.id` is a ULID; `aleph_frontier_candidates.id` is auto-increment.

**Rationale.** Run ids are operator-visible and benefit from being sortable and opaque. Candidate
ordering must be *provably* insertion-ordered, because deterministic totals depend on the claim order
`ORDER BY depth, id`. An auto-increment column makes that self-evident instead of resting on ULID
monotonicity.

## D-009 — External URLs are recorded as skipped candidates

**Decision.** A discovered URL outside the source's allowed hosts is written to
`aleph_frontier_candidates` with state `skipped` and reason `external_host`, carrying its
`parent_id`.

**Rationale.** "Recorded but never recursively crawled" needs the evidence in one place, with the
provenance submitted to Funes discoveries. `claimNext()` only takes `pending` rows, so a skipped row
can never be fetched.

**Rejected.** A separate external-links table, which would split provenance across two schemas.

## D-010 — Aleph stores no response bodies

**Decision.** No table in Aleph has a body or content column, and none may gain one.

**Rationale.** Funes is the canonical content store. Aleph retains only retrieval state, response
metadata, the stable Funes observation reference, and its acceptance disposition.

## D-011 — `NoLinks` is the default link source

**Decision.** `LinkSource` binds to `NoLinks`, which discovers nothing.

**Rationale.** Real HTML link extraction is ALEPH-009. Binding a no-op default means
`aleph:crawl` works end to end today — it fetches the seeds and stops — instead of failing to
resolve. That is an accurate reflection of where the tickets stand.

## D-012 — `retrieved()` and `isOk()` are separate questions

**Decision.** `FetchResult::retrieved()` reports whether a response arrived at all;
`isOk()` reports whether its status was 2xx. A 404 is retrieved but not ok.

**Rationale.** A 404 is evidence, not a transport failure, and must not be recorded as one. The run
summary reports `fetched` and `unsuccessful` separately so an operator can see that a crawl which
"fetched 200 pages" actually got 180 of them.

**Rejected.** A single `succeeded()` in the style of Funes' `ExtractionResult`, which conflates the
two and would have made 404 handling ambiguous.
