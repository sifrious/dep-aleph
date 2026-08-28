# Decision register

Decisions taken while implementing ALEPH-005 (bounded named crawl) and ALEPH-006
(conservative HTTP fetching). Each entry records the decision, why, and what was rejected.

## D-001 — Capability and ingestion-run contracts stop at demonstrated behavior

**Decision.** `Capability` currently admits only `web.crawl`. `IngestionRun` records source,
capability, parameters, lifecycle, stats, and error. Connector manifests, generic attempts, and
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
`robots_disallowed` in the run's failure stats rather than as a silent skip.

**Rejected.** Failing open on transport errors, which would crawl a host that never granted permission.

## D-008 — Runs are ULIDs, candidates are auto-increment

**Decision.** `aleph_ingestion_runs.id` is a ULID; `aleph_frontier_candidates.id` is auto-increment.

**Rationale.** Run ids are operator-visible and benefit from being sortable and opaque. Candidate
ordering must be *provably* insertion-ordered, because deterministic stats depend on the claim order
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

## D-011 — `NoLinks` was the pre-extraction default link source

**Decision.** Before ALEPH-009, `LinkSource` bound to `NoLinks`, which discovered nothing. ALEPH-009 removed both contracts when the production HTML extractor became the only source of document discoveries.

**Rationale.** Before the extractor shipped, the no-op binding let `aleph:crawl` work end to end by
fetching seeds and stopping instead of failing to resolve. Removing it now keeps discovery tied to
the persisted versioned extraction that produced the provenance.

## D-012 — `retrieved()` and `isOk()` are separate questions

**Decision.** `FetchResult::retrieved()` reports whether a response arrived at all;
`isOk()` reports whether its status was 2xx. A 404 is retrieved but not ok.

**Rationale.** A 404 is evidence, not a transport failure, and must not be recorded as one. The run
summary reports `fetched` and `unsuccessful` separately so an operator can see that a crawl which
"fetched 200 pages" actually got 180 of them.

**Rejected.** A single `succeeded()` in the style of Funes' `ExtractionResult`, which conflates the
two and would have made 404 handling ambiguous.

## D-013 — Content type selects one versioned mechanical extractor

**Decision.** Normalized `Content-Type` selects `aleph.html:1`, `aleph.pdf:1`, or
`aleph.unsupported:1`. Every retrieved observation records exactly one of those versioned outcomes
through Funes before its frontier candidate becomes fetched.

**Rationale.** Classification remains deterministic and replayable from preserved observations.
Changing extraction behavior requires a new version rather than silently changing an existing
result fingerprint.

**Rejected.** Choosing from file extensions, which are absent from Google Drive artifact URLs and
can disagree with the retrieved representation.

## D-014 — Discovery type is part of provenance identity

**Decision.** HTML anchors, iframes, and embeds produce `link`, `iframe`, and `embed` relationships.
The relationship participates in the in-memory discovery key and the Funes uniqueness constraint,
so one child URL may retain more than one relationship to the same parent observation.

**Rationale.** An embedded calendar artifact is materially different from a navigational link, and
the critical Waverly proof depends on querying that distinction from Funes.

## D-015 — Embedded PDF text uses `smalot/pdfparser`

**Decision.** `aleph.pdf:1` uses `smalot/pdfparser` behind the single PDF extractor call site. The
dependency tax is recorded in `OWNED-DIFF.md`.

**Rationale.** PHP and Laravel have no PDF text parser. Compressed streams, font encodings, and PDF
object layouts make a small in-package parser unsafe, while shelling out would add an undeclared
host-runtime dependency.

**Revisit if.** Limited upstream maintenance creates unresolved correctness or security failures.

## D-016 — Landing owns the master database vocabulary

**Decision.** Landing remains the complete application schema and source of truth. Aleph appends
prefixed operational tables and uses landing's established names wherever concepts overlap, including
`stats` and `error` for ingestion-run outcomes.

**Rationale.** Package installation must extend the master database without replacing landing-owned
domain tables or introducing a second name for the same persisted concept.

**Rejected.** Copying landing domain models into Aleph or retaining package-local synonyms that need
translation at every database boundary.

## D-017 — The landing ownership matrix settles shared column names

**Decision.** Where Aleph names a concept that the master schema already names, the name comes from
`landing-model-attribute-ownership.csv`. That matrix assigns `aleph/core` the ingestion-run concept
with `id`, `source_reference`, `capability`, `status`, `parameters`, `stats`, `error`, `started_at`,
and `finished_at`, and it assigns `funes/core` the observation concept with `observations.observed_at`,
`observations.ingested_at`, `observations.payload_hash`, and `payloads.byte_size`. Extraction facts
carried onto another table use landing's `extraction_status` and `extraction_error`.

**Rationale.** D-016 states the principle; the matrix is the artifact that resolves it case by case.
`aleph_ingestion_runs` already matched. `aleph_frontier_candidates` did not: `fetched_at` was a
package-local synonym for the value Aleph passes to Funes as `observedAt`, so it is now `observed_at`,
and the new columns are `payload_hash`, `byte_size`, and `ingested_at` rather than `hash`, `size`, or
`accepted_at`.

`fetched_at` and `extraction_failure` were package inventions with nothing in the master schema to
match, so both were renamed toward names landing already carries: `observed_at` (`pr_snapshots`) and
`extraction_error` (`attachments`). `extraction_version` extends the same `extraction_` prefix for a
fact landing has no column for; a bare `version` would say nothing on a frontier row, and a bare
`failure` already means the transport failure on that table.

**Rejected.** Keeping `fetched_at` alongside a second `observed_at` column, which would have stored
one instant under two names.

## D-018 — Aleph records what the acceptance boundary returned

**Decision.** `FunesObservationWriter::accept()` returns `AcceptedRetrieval`, and the frontier row
stores the observation reference, disposition, payload hash, byte size, ingestion time, and the
versioned extraction outcome that Funes handed back.

**Rationale.** The inventory needs hashes, sizes, and extraction status per candidate. Reading them
back out of Funes' tables would cross the boundary D-002 established; recomputing the hash in Aleph
would create a second authority for the same value. Taking them from the returned value does neither.
This follows the precedent already set by `final_url`, `http_status`, and `content_type`, which the
frontier records about its own attempt even though Funes also holds them.

**Rejected.** Extending `ObservationStore` with an inventory-shaped read, which would change another
package's contract before a second consumer needs it.

**Note.** D-010 is unaffected. A hash and a byte count are not a body, and no Aleph table gained a
content column.

## D-019 — Calendar-like is configured evidence, not vendor knowledge

**Decision.** `calendar_signals` is per-source configuration matched against the canonical path. No
SchoolMessenger URL shape is compiled into Aleph. The `ahsd` patterns are justified request by
request in `docs/investigations/schoolmessenger.md`.

**Rationale.** ALEPH-011 admits only captured working requests as evidence. Recalled vendor route
conventions are not evidence, and the capture disproved one: AHSD calendar pages are readable slugs
such as `/our_school/calendar`, not a platform module path. Configuration keeps the classification
answerable to the next capture.

**Rejected.** A `SchoolMessengerClassifier` in `src/`, which would freeze a guess into code and would
need deleting the first time a district differed.

## D-020 — A resource is calendar-like by path or by embedding

**Decision.** `CalendarSignal::Path` marks a canonical path that matches a configured signal.
`CalendarSignal::EmbeddedInCalendar` marks a resource discovered as an `iframe` or `embed` whose
parent is calendar-like by path. Embedding is resolved one level; it does not chain.

**Rationale.** The Waverly artifact is a Google Drive URL with nothing calendrical in it. It is a
calendar artifact only because of the edge that reaches it, which is precisely the provenance
ALEPH-009 records. One level is what the captured evidence shows and what the proof needs.

## D-021 — Freshness separates this run from what is merely known

**Decision.** Every inventory row is `current` (this run produced its observation), `stale` (Funes
holds an observation for the canonical resource but this run did not produce it), or `unobserved`
(no observation exists). HTTP and extraction columns always describe this run; observation columns
describe the most recent content evidence, and `last_observed_at` says when that was.

**Rationale.** A page-limited or interrupted run leaves candidates whose content is known but not
current. Reporting them as empty rows would hide the distinction between "never seen" and "not seen
this time", which is the distinction an operator is actually asking about.

## D-022 — The inventory is deterministic by construction

**Decision.** Column order is fixed by `InventoryResource::columns()`, rows are sorted in PHP by
`strcmp` on the canonical URL, instants are rendered as UTC `Y-m-d\TH:i:s\Z`, and no generation
timestamp is embedded. The same crawl state exports byte-identical JSON and CSV.

**Rationale.** "Reproducible" has to be a testable property, not an intention. Sorting in PHP rather
than in SQL removes the database collation from the answer, and omitting a generation timestamp is
what lets two exports be diffed at all.

**Rejected.** `fputcsv`, whose escape-character default is deprecated and whose behaviour has moved
between PHP versions; the encoder is a dozen lines of RFC 4180.

## D-023 — An empty response body is an empty document

**Decision.** `aleph.html:1` returns an empty text extraction with zero references for a body-less
response instead of throwing.

**Rationale.** The inventory surfaced this: a 404 with no body was being recorded as
`extraction_status = failed`, which claims the extractor broke when nothing was wrong with it.
`DOMDocument::loadHTML('')` returns false for empty input, which is a quirk of that function rather
than a parse error. Fixed in place rather than as `aleph.html:2` because no `aleph.html:1` result has
ever been recorded outside this repository's tests; once a version has shipped, D-013 applies and a
behaviour change requires a new version.

## D-024 — One source reference, spelled the same in both packages

**Decision.** `aleph_ingestion_runs.source_reference` stores the prefixed form `web:ahsd`, byte-identical
to `funes_sources.reference`. `WebSource::reference()` is the only place the `web:` prefix is written.
The CLI argument and the configuration key stay the bare `ahsd`.

**Rationale.** The run row and the Funes source row describe the same source, so joining them should
be an equality, not an equality plus a prefixing rule that lives in Aleph's code rather than in the
schema. The prefix was already being interpolated at two call sites, so a second capability would
have made `source_reference` ambiguous across connectors — `web:ahsd` and `github:sifrious/aleph` do
not collide, `ahsd` and `sifrious/aleph` say nothing about which connector produced them.

Landing offers no competing convention to match: its sync-run tables identify a source by foreign key,
and its `source` columns hold bare kinds such as `disk`. Funes' `reference` is therefore the only
existing string convention for this concept, and Aleph now matches it exactly.

**Timing.** Free now. Neither `aleph_*` nor `funes_*` tables exist in landing's database yet, so no
persisted row changes meaning. After installation this would be a data migration.

**Rejected.** A `source` plus `source_reference` pair mirroring landing's `notes.source` /
`notes.source_ref` shape. The ownership matrix assigns this concept a single `source_reference`, and
landing's own instances of that pair are unused.

## D-025 — An inventory refuses a run that belongs to another source

**Decision.** `aleph:inventory <source> --run=<id>` fails when the run's `source_reference` is not the
named source's. The run's crawl parameters are applied only after that check.

**Rationale.** The inventory reads its bounds from two places: the run row supplies status, stats, and
the recorded limits, while the configured source supplies seeds, allowed hosts, exclusions, and
calendar signals. Nothing joined them, so a mismatched pair produced a document that contradicted
itself — `source_reference: web:middle` beside `source_name: Waverly Elementary` — and, worse, judged
every row's `external` flag against the wrong host policy, marking a source's own crawled homepage
external. A report whose provenance columns disagree is worse than no report, because it is diffable
and looks authoritative.

The guard belongs on the `--run` path only. `latest()` already filters by source reference, so the
default path cannot produce the mismatch.

**Rejected.** Deriving the source from the run instead of the argument. The source key is the
command's required argument and its configuration is what supplies calendar signals; silently
inventorying a different source than the one named would trade a visible contradiction for an
invisible one.

## D-026 — Extraction status is a typed vocabulary

**Decision.** `succeeded` and `failed` are `ExtractionStatus` cases rather than string literals.

**Rationale.** The spelling was written in `AcceptedRetrieval` and matched in `Inventory::totals()`,
two files apart, with nothing tying them together. The inventory's `extraction_errors` total would
have silently reported zero if either side were respelled. Every other vocabulary Aleph persists —
frontier state, skip reason, fetch failure, discovery origin, calendar signal, freshness — is already
an enum; this was the one that was not.

## D-027 — Connector capability is a separate vocabulary from ingestion-run capability

**Decision.** `Connector\Capability` is a new enum of nine connector capabilities. The existing
`Ingestion\Capability` (currently only `web.crawl`) stays as it is.

**Rationale.** They answer different questions. `Ingestion\Capability` records *what kind of run
this was* and is already persisted in `aleph_ingestion_runs.capability`. `Connector\Capability`
records *what a connector can be asked to do*, and every case maps one-to-one onto an interface.
Merging them would break that one-to-one mapping — `web.crawl` has no contract — and would
migrate live data for no behavioural gain.

**Rejected.** Adding nine cases to `Ingestion\Capability`. It reads tidier and is wrong: dispatch
would then accept a capability with no interface behind it.

**Follow-up.** When generic ingestion runs arrive (A38 and the run-model tickets), decide whether
a run's capability should become a connector capability. Recorded, not absorbed.

## D-028 — Capabilities are derived from interfaces, never declared

**Decision.** `CapabilitySet::of($connector)` computes capabilities by testing `instanceof`
against `Capability::contract()`. `ConnectorManifest::for($connector)` is built from that. A
connector author cannot write a capability list.

**Rationale.** The ticket requires that declared capabilities cannot silently drift from the
interfaces actually implemented. Making the manifest derived rather than declared removes the
possibility structurally, instead of policing it with a test. Adding a capability is one act —
implement the interface.

**Rejected.** A `capabilities(): array` method on the connector, or a static manifest array. Both
create a second source of truth that a developer can forget to update.

## D-029 — No `connector_capabilities` table

**Decision.** MME-853 adds no persistence. Capability discovery is `ConnectorRegistry` plus
`instanceof`, in memory.

**Rationale.** The ticket asks whether Aleph actually needs persisted capability discovery *at
this stage*. It does not. Capabilities are a property of deployed PHP classes: a table would be a
cache of the code, invalidated by every deploy, and able to disagree with the running system —
exactly the drift D-028 exists to prevent. Registered connectors are enumerable in microseconds.

**Rejected.** Creating the table now "because the ticket mentions it". The ticket explicitly marks
it optional and prefers behaviour in the package.

**Revisit when.** Aleph needs to answer capability questions *across hosts* or *about connectors
not loaded in the current process* — for example a status dashboard querying which deployments
support incremental sync. At that point the table becomes a projection of package-owned truth,
rebuilt on deploy, and must never be read as authoritative.

## D-030 — Extraction and normalization are capabilities but not operations

**Decision.** `Capability::isDispatchable()` is false for `content.extract`, `records.normalize`,
and `agents.assist`. The dispatcher refuses them with `capability_not_dispatchable`.

**Rationale.** Discovery, backfill, incremental sync, webhook consumption, download, and health
check are things an operator or scheduler asks a connector to do. Extraction and normalization
happen *inside* a run, on material the run already fetched. Letting the dispatcher start a
"normalize run" would invent an operation with no meaningful source reference.

**Rejected.** Treating all nine uniformly. It reads consistent and would let the UI offer a button
that cannot do anything.

## D-031 — `MechanicalExtractor` is retained, not replaced

**Decision.** The existing `Extraction\MechanicalExtractor` seam is untouched. The new
`Contracts\ExtractsContent` sits at the connector level and does not supersede it.

**Rationale.** `MechanicalExtractor` is already a narrow, well-shaped contract
(`format`/`name`/`version`/`extract`) specialised to `Web\FetchResult`, and the crawl pipeline
depends on it. `ExtractsContent` answers a different question — whether a *connector* can extract
from artifacts it produced. Replacing a working seam to make the naming symmetrical would be
churn.

**Follow-up.** If a second connector implements `ExtractsContent`, check whether the web
extractors should be expressed through it. Not now.

## D-032 — Funes was already provider-neutral; A38 froze the Aleph side

**Historical note.** The provider-neutral conclusion remains true, but the JSON-slot persistence
described here was superseded by Funes' typed metadata assertions and D-059.

**Decision.** No change to Funes. `ObservationEnvelope` (Aleph) plus `EnvelopeSubmitter` is the
one place Aleph talks to `ObservationStore`.

**Rationale.** Inspection found Funes' schema already universal — `funes_sources`,
`funes_resources`, `funes_payloads`, `funes_observations`, `funes_discoveries`,
`funes_extractions`, with `metadata` as a JSON slot and no provider column anywhere. The gap A38
names was never in Funes; it was the absence of a *frozen, versioned* contract on the Aleph side
telling connectors how to fill that slot.

**Rejected.** Adding envelope classes to Funes. Funes would then own a concept only Aleph uses.

## D-033 — Reserved `aleph` namespace, everything else is a versioned extension

**Persistence note.** The logical split remains, but its physical representation is superseded by
D-059: `aleph:envelope` and `aleph:extension/<name>` assertions replace the shared JSON object.

**Decision.** Observation metadata has exactly two top-level keys: `aleph` (envelope version,
account, stream, event type, provider id/revision, artifacts, provenance) and `extensions` (a
list of `{namespace, version, data}`). `aleph` and `funes` are reserved namespace roots and
`ExtensionMetadata` refuses them.

**Rationale.** Funes must preserve provider-specific data without understanding it. A reserved
namespace for fields Funes-adjacent tooling may one day read generically, and a versioned list for
everything else, keeps those two populations separable forever. Refusing the reserved roots at
construction stops a connector squatting on them.

**Rejected.** Flat metadata merging, which makes provider keys indistinguishable from universal
ones and collides the first time two connectors pick the same key.

## D-034 — No `connectors` table; the catalogue is projected

**Decision.** `ConnectorCatalogue` projects from `ConnectorRegistry` at runtime, deriving the
package name by reflecting the connector class file up to its `composer.json`. Global
enable/disable is `aleph.connectors.disabled` in config.

**Rationale.** Same reasoning as D-029. Installed software is a property of the deployed
filesystem: a `connectors` table would be a cache of Composer's state, stale after every deploy.
Deriving the package name means the connector author declares nothing extra.

**Rejected.** Persisting `connectors` rows. The ticket offers it; it would be a second source of
truth for something Composer already knows.

## D-035 — Installations are persisted, because they are operator state

**Decision.** `aleph_connector_installations` exists and stores configuration encrypted via
Laravel's `Encrypter`, plus a `credentials_reference` pointer rather than credential values.

**Rationale.** Unlike the catalogue, a configured installation is not derivable from code. It is
what an operator created. It must survive deploys and support several installations of one
connector, so it is genuinely persisted state rather than a projection.

**Rejected.** Storing raw configuration JSON. Connector configuration routinely contains
host names, account identifiers, and occasionally worse; encrypting the column costs nothing and
keeps secrets out of database dumps. Credential *values* still belong in a secret store, which is
why the row holds a reference.

## D-036 — The sample connector is a pigeon courier, not Slack

**Decision.** `sifrious/aleph-connector-pigeonpost` is the plugin-boundary proof.

**Rationale.** The ticket asks for something no existing shortcut could accidentally support. A
loft receiving ring-numbered dispatches with wind readings has no analogue anywhere in Aleph or
Funes, so a passing test cannot be explained by a latent provider assumption. Slack or GitHub
would have been ambiguous evidence.

## D-037 — A39's `Normalizes` became a normalizer *provider*, not a normalizer

**Decision.** `Normalizes` is now `normalizers(): list<Normalizer>`. The single-method
`normalize(RawRecord): NormalizedRecord` from A39 is gone, along with `RawRecord` and
`NormalizedRecord`.

**Rationale.** A39 shipped a placeholder before anything implemented it. It was wrong in three
ways A32 makes plain: it forced one-to-one normalization (a transcript is one input and many
messages), it returned a finished record rather than a *candidate*, and it gave a connector
exactly one normalizer when Slack alone needs several. Nothing implemented it except a fake, so
replacing it cost nothing.

**Rejected.** Keeping the old method alongside the new contract. Two ways to normalize is the
duplicate-truth problem A38 spent a whole ticket avoiding.

## D-038 — Normalizer version and candidate schema version are separate fields

**Decision.** `NormalizerIdentity` (`shell-command@3`) and `CandidateSchema`
(`activity.command@2`) are distinct value objects, both recorded on every attempt and in every
accepted record.

**Rationale.** They change for different reasons. Fixing a tokenizer bug bumps the normalizer and
leaves the output shape alone; adding a required field to the candidate bumps the schema while
several normalizer versions keep emitting it. Collapsing them loses the ability to say which one
moved, which is exactly the question you ask when reprocessing.

**Rejected.** A single version, and using the application release as a substitute. Deployment
version tells you when code shipped, not which interpretation produced a record.

## D-039 — Lineage lives in the reserved `aleph` namespace, not an extension

**Decision.** `ObservationEnvelope` gained an optional `normalization` block emitted inside the
reserved `aleph` metadata namespace, carrying normalizer reference, schema reference, and the raw
reference with its input hash.

**Rationale.** The A38 rules say `extensions` is for provider-specific data Funes cannot
understand. Normalization lineage is the opposite — Aleph-owned, provider-neutral, and the thing
the acceptance criterion demands be readable on any accepted record. Putting it in an extension
would have made Aleph's own bookkeeping look like a provider's.

**Consequence.** The field is optional, so every A38 envelope built before A32 still validates.

## D-040 — The cache stores serialized candidates, keyed by evidence and version

**Decision.** `NormalizationCache` keys on
`sha256(input_hash | normalizer id | normalizer version | schema reference | context version)` and
stores `serialize()`d `CandidateEnvelopes`, treating any unserialize failure as a miss.

**Rationale.** The key must contain the normalizer version or a new version silently returns the
old interpretation — the ticket names this explicitly. Serialization was chosen over a hand-written
array codec because the cache is a pure optimisation: a miss is always safe, and a codec for the
whole envelope graph is a lot of surface to maintain for no correctness gain.

**Tradeoff.** A class-shape change invalidates cached entries. Acceptable, because a miss just
re-runs the normalizer.

## D-041 — Attempts are append-only; re-normalization never rewrites lineage

**Decision.** Every run inserts a new `aleph_normalization_attempts` row. Nothing updates an
existing one, including cache hits, which are recorded with `cached = true`.

**Rationale.** "Which normalizer produced this record" is a historical fact about the past, not a
current-state field. Reprocessing evidence under a better normalizer must add a second lineage,
not overwrite the first — otherwise you lose the ability to explain a record accepted last year.

## D-042 — A33 provenance is stubbed by A38's `Provenance`

**Decision.** `NormalizationInput` carries `Envelope\Provenance` (connector, connector version,
installation, capturedAt, run) as the provenance value, and the validator asserts candidates
preserve it.

**Rationale.** A32's handoff assumes A33 exists. It does not. A38's `Provenance` is real
connector-execution provenance and satisfies every A32 criterion that mentions it, so A32 could
proceed rather than block.

**Follow-up.** When A33 defines canonical source identity, it should enrich `Provenance` in place.
The normalization seam takes whatever `Provenance` holds and needs no change. Recorded, not
absorbed.

## D-043 — Idempotency is reserved-then-filled inside one Funes transaction

**Decision.** `SqlAcceptanceGateway` opens a transaction, `insertOrIgnore`s the key row, and reads
the affected count. One means this caller won the key and may accept; zero means someone else
holds it, and the existing row decides whether the answer is `replayed` (accepted_id present) or
`in_flight` (still null).

**Rationale.** The ticket forbids check-then-insert, and rightly: two crawlers submitting the same
page race between the SELECT and the INSERT. The unique primary key arbitrates instead of
application logic, so the database decides exactly once.

**Consequence.** `in_flight` is a real fourth outcome. It is not an error and not an acceptance —
it means retry later, and the Aleph submission is marked retryable.

## D-044 — The key derives from submission identity, and includes the normalizer version

**Decision.** `v1:sha256(source | account | stream | resource | provider id | provider revision |
candidate schema@version | normalizer@version | input hash)`.

**Rationale.** Everything that makes a candidate a *distinct accepted fact* is in the key, and
nothing else. Including the input hash means changed content is a new fact rather than a silent
replay. Including `normalizer@version` means a better normalizer re-reading preserved evidence
produces new lineage instead of colliding with the old interpretation — which is what A32's
re-normalization requires.

**Rejected.** A random UUID per request, which makes retries duplicate history, and a
provider-id-only key, which makes edited content invisible.

## D-045 — `ingested_at` is the accepted time; `occurred_at` was added

**Decision.** Three distinct timestamps: `occurred_at` (when it happened, nullable, provider-
supplied), `observed_at` (when Aleph saw it), `ingested_at` (when Funes accepted it).

**Rationale.** A34 requires all three distinct. Funes already had observed and ingested; only
`occurred_at` was missing. An uncommitted refactor in the Funes tree had just renamed
`accepted_at` to `ingested_at`, so rather than revert someone's deliberate rename I added the
missing column and documented that `ingested_at` *is* the acceptance stamp.

**Validation.** The gateway rejects `occurred_at` later than `observed_at` — you cannot observe
something before it happened.

## D-046 — The web crawl was the representative migration, not Slack

**Decision.** `FunesObservationWriter` now normalizes through `WebRetrievalNormalizer` and submits
through `AcceptanceClient`. It no longer builds an `ObservationDraft` inline.

**Rationale.** The ticket suggests Slack, but Slack lives in Landing and cannot be run here. The
crawl path was the genuine parallel authority *in this codebase*: mature, exercised by real tests,
and carrying provenance, discoveries, extraction, and dispositions. Migrating it proved more than
a toy would have — it surfaced two real regressions (below).

**Parity.** Metadata moved from flat keys to `extensions[web.retrieval].data`, with
`aleph.provenance` and `aleph.normalization` added. Payload, discoveries, relationships,
dispositions, and extraction records are unchanged. That difference is intentional and the crawl
persistence tests now assert the new shape.

## D-047 — Two regressions the migration caught

**Discovery relationships.** The envelope's `discoveries` was `list<string>`, so `iframe` and
`embed` relationships collapsed to the default `discovered`. Fixed by introducing
`DiscoveryReference`; the envelope now carries the relationship through to Funes.

**Disposition flattening.** The writer mapped any acceptance to `First`, losing Funes'
`Changed`. Fixed by threading the real `ObservationDisposition` from the acceptance result.

Both were caught by pre-existing crawl tests rather than by new ones, which is the argument for
migrating a mature path instead of a toy.

## D-048 — `EnvelopeSubmitter::submit()` was the hole in A34's first pass

**Decision.** `EnvelopeSubmitter` no longer holds an `ObservationStore`. Drafting moved to a new
`EnvelopeDrafter`; `submit()` now wraps the envelope as a candidate and goes through
`AcceptanceClient`, returning an `AcceptanceOutcomeRecord` instead of an `AcceptedObservation`.

**Rationale.** A34's first pass migrated the crawl path but left A38's connector-facing API
writing straight to `ObservationStore::accept()`. The sample connector used exactly that call, so
MME-841's one binding criterion — no connector creates accepted history without a Funes acceptance
result — was still false. The crawl migration was real; it just was not the whole boundary.

**Consequence.** Connectors must now read the result. `PigeonPostConnector::backfill()` fails the
operation when Funes does not accept, instead of counting an assumed success. The dependency
`AcceptanceClient → EnvelopeDrafter → (values only)` has no cycle, and `EnvelopeSubmitter` no
longer imports `Funes\Persistence` at all.

## D-049 — A directly submitted envelope is its own candidate

**Decision.** `CandidateEnvelope::forEnvelope()` reuses the envelope's existing
`NormalizationLineage` when A32 produced one, and otherwise stamps
`aleph.envelope@1` / `aleph.direct@1` with a raw reference hashed from the payload.

**Rationale.** A connector that emits an envelope directly has done no normalization, and saying so
is more honest than inventing a normalizer name. The first draft of this change stamped `direct` on
every envelope and silently overwrote real `shell-command@3` lineage — caught by A32's seam test,
which is the argument for keeping that test.

**Consequence.** Direct submissions still carry lineage, still get a content-derived idempotency
key, and are still replay-safe. They are distinguishable from normalized ones by normalizer id.

## D-050 — The backfill reads a Funes backlog rather than guessing

**Decision.** Funes exposes `AcceptanceBacklog::unkeyed()` — accepted observations with no
idempotency key, i.e. history persisted before the boundary existed. Aleph's `Backfill` submits
each one back through `AcceptanceClient` in bounded batches.

**Rationale.** Only Funes can say which of its records were accepted without proof. Aleph asking
"what have you not keyed?" keeps the judgement on the Funes side of the boundary, and the backfill
uses the same acceptance contract as live ingestion rather than a privileged migration path.

**Consequence.** Backfill does not duplicate history: `ObservationStore::accept()` already dedupes
on payload hash, so a legacy row keeps its id and gains a key plus an
`aleph_funes_submissions` row. Running it repeatedly is a no-op — the second run's backlog is
empty. `occurred_at` survives, reconstructed from the stored `aleph.occurred_at` metadata.

## D-051 — Batch acceptance settles per candidate, not per batch

**Decision.** `AcceptanceClient::submitAll()` loops, and each candidate gets its own idempotency
key, its own Funes transaction and its own `aleph_funes_submissions` row. A rejection does not roll
back its neighbours.

**Rationale.** The ticket asks for atomic semantics "where appropriate". Per-batch atomicity would
mean one malformed record from a provider discards a whole page of good history, and a retry of the
batch would then be indistinguishable from a retry of the failure. Per-candidate atomicity is the
unit that actually matters: one candidate is one accepted fact.

**Consequence.** A partially failing batch is a normal outcome, reported per record. An uncertain
retry is safe because the candidates that succeeded replay by key. `acceptBatch()` on the Funes
gateway keeps the same per-submission semantics.

**Rejected.** Wrapping the batch in one transaction, which trades a real correctness property
(good records land) for a cosmetic one (the batch is all-or-nothing).

## D-052 — Parity is a fixture, not a paragraph

**Decision.** `tests/Feature/CrawlParityTest.php` pins the pre-A34 metadata key list as a literal
constant and asserts every key and value survives into
the `aleph:extension/web.retrieval` assertion, that the envelope additions are exactly
`envelope_version`, `event_type`, `provenance`, `normalization`, and that payload, references,
content type, discovery relationships, dispositions, counts and extraction records are unchanged.

**Rationale.** D-046 claimed parity in prose. Prose does not fail when someone drops a key. Writing
the assertions found that two of my own expectations were wrong (`text/html; charset=utf-8`, and
relationships `link`/`iframe` rather than `discovered`) — the second confirming D-047's fix holds.

**Consequence.** The legacy crawl write path is retired rather than dual-written. Dual-writing was
rejected because the migrated path *is* the only writer and a recorded fixture proves the same
thing without shipping temporary architecture the ticket forbids making permanent.

## D-053 — Follow-up connector migrations, not absorbed here

The remaining historical writers stay in Landing, one ticket each, in this order: Slack (largest
and most shape-stable), Claude history, Linear, GitHub, artifacts, research. `docs/historical-
writers.md` records what each writes and which are operational rather than historical. Landing's
~80 CRUD controllers are display-layer state and are not migration candidates.

## D-054 — Source scopes use typed opaque references and explicit epistemic state

**Decision.** A source installation or stream associates with Funes `EntityReference` values of
kind project, identity, repository, organization, or domain. The identifier must be namespaced;
host database IDs and unqualified display names are rejected. Associations are confirmed,
ambiguous, rejected, or superseded, and absence is projected as unassigned.

**Rationale.** Landing has several useful local associations, but no portable source-scope
relation. A path match, display-name match, or integer foreign key cannot cross application
boundaries without turning a host inference into package fact. Multiple rows preserve both
many-to-many truth and unresolved candidates without selecting a winner.

**Consequence.** Acceptance snapshots the installation, stream, resolution state, and every
association into the immutable observation metadata. Later changes to current associations do not
rewrite the explanation attached to earlier history.

## D-055 — One provider-neutral ingestion run absorbs `SyncRun` without copying its UI model

**Decision.** The existing Aleph ingestion run remains the package object and gains connector and
installation identity, stable legacy reference, replay key, checkpoint, completeness, structured
failure, counters, accepted Funes references, and stable serialization. Landing's ordered subway
step tree crosses the boundary as an opaque checkpoint, not as Aleph's lifecycle model.

**Rationale.** Landing's `SyncRun` mixes useful transport state with repository and presentation
details. Creating a second package run abstraction would split lifecycle authority, while copying
step semantics would make every connector pretend to be a GitHub repository pipeline.

**Consequence.** Legacy IDs map deterministically to ULIDs, repeated imports and launch keys return
the original run, partial and incomplete outcomes remain explicit, and Landing retains its table
until a consuming-host reconciliation proves cutover.

## D-056 — Attempts are durable children of one idempotent ingestion run

**Decision.** A logical source window has one ingestion run and one or more numbered attempts. Each
attempt records its own checkpoint, counters, structured failure, and timing. The run projects the
latest operational state and retains deduplicated stable references to history accepted by Funes.

**Rationale.** Treating every queue retry as a new run loses lineage and makes already accepted
observations look like new work. Keeping attempts only in queue metadata loses the evidence an
operator needs after a worker exits. A separate provider orchestration framework is unnecessary;
the run, attempt, transition rules, and query are the smallest coherent boundary.

**Consequence.** Retry resumes from the committed checkpoint, fatal failures cannot be retried,
partial and retryable failures remain diagnosable, and two hosts can inspect the same stable read
model without querying package tables or importing Landing types.

## D-057 — Domain run state is a projection, not a second lifecycle

**Decision.** Domain and DNS operational state composes the provider-neutral ingestion read model.
Its DNS extension carries account/domain scope, stable provider-account and domain references, and
provider reconciliation IDs. Checkpoints, counters, attempts, failures, timing, and completeness
remain on the shared run.

**Rationale.** Landing's `DomainSyncRun` repeats the same pending/running/succeeded/failed lifecycle
used by every provider and adds only DNS scope. A second lifecycle would immediately drift. Putting
SDK response objects in the projection would make the package contract provider-specific.

**Consequence.** Landing rows migrate deterministically, DNS consumers receive a typed projection,
and the extension may grow only from source-backed DNS fields. Historical domain and record facts
remain Funes observations referenced by stable IDs.

## D-058 — Manual launch records authorization before dispatch

**Decision.** `LaunchIngestion` accepts one presentation-neutral request containing installation,
source, advertised capability, JSON-safe parameters, an idempotency key, and a previously evaluated
authorization decision. It persists or resolves the pending logical run before calling the host's
`ManualIngestionDispatcher` port. A replay returns the original run and is not dispatched twice.

**Rationale.** Landing's commands and controllers validate, create provider-specific rows, and
dispatch in different orders. Aleph should own identical launch semantics without owning HTTP,
console formatting, mobile authentication, or queue transport. Authorization policy belongs to the
host, while the decision evidence belongs on the run.

**Consequence.** CLI, desktop, and phone adapters produce the same run shape. Disabled or unknown
installations, denied decisions, unsupported capabilities, and nonportable parameters fail before a
run exists. Dispatch adapters can prove the run was durable before receiving it.

## D-059 — Aleph projects envelopes into typed Funes metadata assertions

**Decision.** Universal envelope attributes cross the Funes boundary as one versioned
`aleph:envelope` assertion. Each connector extension crosses independently as
`aleph:extension/<namespace>`, using its extension version as the assertion schema version. Aleph
reconstructs envelopes from those typed assertions during backfill.

**Rationale.** Funes now preserves append-only metadata with explicit namespace, schema version,
provenance, and recording time. Rebuilding the former nested JSON slot would discard those semantics
and retain a second metadata model. One assertion per genuine namespace preserves provider
neutrality and lets an extension evolve without rewriting the universal envelope.

**Consequence.** Accepted observations no longer expose Aleph metadata through the legacy
`funes_observations.metadata` JSON column. Package readers use `ObservationMetadata`, parity tests
pin the assertion namespaces and values, and backfill retains both universal and extension data.

## D-060 — Queue runtimes transport durable Aleph attempts

**Decision.** Aleph creates a pending ingestion attempt before calling `IngestionQueue`. The attempt
owns its queue class, priority, connector/source/run/attempt tags, dispatch policy, runtime job ID,
worker ID, heartbeat, structured failure, and timing. Runtime queue records are disposable transport
state and never become the operator's source of truth.

**Rationale.** Landing jobs create or update several provider-specific run rows and rely on Laravel
queue failure hooks. Those hooks are useful host behavior, but Laravel job IDs and Horizon retention
cannot explain a connector attempt after pruning or support a non-Laravel worker. Extending the
existing numbered attempt is the smallest boundary that preserves lineage without a second queue
framework.

**Consequence.** `ingest`, `normalize`, `media`, and `agentic` are stable queue classes. A host
adapter receives explicit per-installation concurrency and rate budgets and returns one opaque job
identity. Starting work records the worker and first heartbeat; an expired heartbeat becomes a
retryable failed attempt, and retries increment the attempt number on the same logical run.
