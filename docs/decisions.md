# Decision register

Decisions taken while implementing ALEPH-005 (bounded named crawl) and ALEPH-006
(conservative HTTP fetching). Each entry records the decision, why, and what was rejected.

## D-001 — ALEPH-001's connector contracts were not built first

**Decision.** ALEPH-005 was implemented concretely, without the connector, capability, attempt,
and checkpoint contracts ALEPH-001 describes.

**Rationale.** None of ALEPH-005's acceptance criteria need those abstractions. They need a source
configuration, a frontier, canonicalization, limits, and totals. Building a contract set with one
implementor would be speculative infrastructure.

**Rejected.** Implementing ALEPH-001 first. ALEPH-001 should be re-scoped against the contracts
005 and 006 actually produced — `Fetcher`, `LinkSource`, `Clock`.

## D-002 — ALEPH-007 deferred until Funes is ready

**Decision.** No persistence to Funes. Aleph keeps operational run state only.

**Rationale.** Funes' `ObservationStore::accept()` returns an `Observation`, not the
first/unchanged/changed disposition ALEPH-007's criteria require, and has no artifact reference for
large binaries. FUNES-006/007/008 are open.

**Rejected.** Building an Aleph-side port with an in-memory fake now. Deferred by explicit
instruction to avoid designing against a contract still being written.

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
`funes_resources`, so ALEPH-007's identity mapping becomes a direct correspondence.

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

**Decision.** `aleph_crawl_runs.id` is a ULID; `aleph_frontier_candidates.id` is auto-increment.

**Rationale.** Run ids are operator-visible and benefit from being sortable and opaque. Candidate
ordering must be *provably* insertion-ordered, because deterministic totals depend on the claim order
`ORDER BY depth, id`. An auto-increment column makes that self-evident instead of resting on ULID
monotonicity.

## D-009 — External URLs are recorded as skipped candidates

**Decision.** A discovered URL outside the source's allowed hosts is written to
`aleph_frontier_candidates` with state `skipped` and reason `external_host`, carrying its
`parent_id`.

**Rationale.** "Recorded but never recursively crawled" needs the evidence in one place, with the
provenance ALEPH-007 will map to Funes discoveries. `claimNext()` only takes `pending` rows, so a
skipped row can never be fetched.

**Rejected.** A separate external-links table, which would split provenance across two schemas.

## D-010 — Aleph stores no response bodies

**Decision.** No table in Aleph has a body or content column, and none may gain one.

**Rationale.** ALEPH-007 requires that Aleph never becomes a second canonical content store. Holding
that line now, while 007 is deferred, is what makes 007 a mapping exercise rather than a migration.

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
