# Glossary

Terms are chosen to align with Funes where the two packages meet, so that ALEPH-007 is a mapping
rather than a translation.

**Web source** — a named, configured site to crawl: seeds, allowed hosts, exclusions,
canonicalization rules, and limits. Keyed by a short name (`ahsd`) used as the CLI argument.

**Seed** — a starting URL declared in configuration. Enters the frontier at depth 0 with discovery
origin `seed`.

**Frontier** — the set of URLs a run knows about, each with a state. Not a queue: it retains skipped
and external URLs as evidence.

**Candidate** — one row in the frontier. Carries canonical URL, canonical hash, requested URL, host,
depth, discovery origin, parent candidate, state, and retrieval outcome.

**Canonical URL** — the normalized form used for identity: scheme and host lowercased, default port
and fragment dropped, dot segments resolved, query reduced to the allowlist and sorted. Two
references that canonicalize alike are the same resource.

**Canonical hash** — sha256 of the canonical URL. The indexed identity (D-004).

**Discovery origin** — `seed`, `link`, `iframe`, or `embed`. Preserves how a resource entered the
operational frontier.

**Discovery relationship** — the typed Funes edge from one observed HTML resource to a canonical
resource discovered through an anchor, iframe, or embed.

**Mechanical extraction** — a deterministic, versioned derivation from preserved response bytes.
Current classifications are HTML, PDF, and unsupported.

**Extractor selector** — chooses `aleph.html:1`, `aleph.pdf:1`, or `aleph.unsupported:1` from the
normalized response media type.

**Skip reason** — why a candidate was recorded but never enqueued: `external_host`, `excluded`, or
`depth_limit`.

**Ingestion run** — one idempotent logical execution of a declared capability against a connector
source. It owns numbered attempts; a web crawl additionally owns a frontier.

**Capability** — a provider-neutral connector operation. `web.crawl` is the first with a complete
executable package pipeline; the contract also names discovery, sync, webhook, artifact, and health
operations used by connector packages.

**Stop reason** — why the loop ended: `frontier_exhausted` or `page_limit`.

**Retrieved** vs **ok** — a fetch is *retrieved* if any response arrived, and *ok* if the status was
2xx. A 404 is retrieved but not ok, and is evidence rather than failure (D-012).

**Fetch failure** — a transport-level outcome with no response: `connection_failed`, `timeout`,
`too_large`, `too_many_redirects`, `robots_disallowed`.

**Observation** *(Funes)* — the immutable content record of one retrieval. Aleph stores its stable
reference and acceptance disposition but not its content (D-010).

**Inventory** — a deterministic report of one ingestion run: its bounds, its totals, and one row per
frontier candidate joined to what the Funes boundary returned. Derived on read; it stores nothing.

**Crawl bounds** — the limits and policy a run actually applied, read back from the run's recorded
parameters rather than from current configuration: page and depth limits, seeds, allowed hosts, host
restrictions, exclusions, query allowlist, and calendar signals.

**Freshness** — `current`, `stale`, or `unobserved`. Whether the row's content evidence came from
this run, from an earlier one, or does not exist (D-021).

**Calendar signal** — why a resource is calendar-like: `path` for a canonical path matching the
source's configured signals, `embedded_in_calendar` for an `iframe` or `embed` child of a
calendar-like page (D-020).

**External embed** — an inventory row whose host is outside the source's allowed hosts and whose
discovery origin is `iframe` or `embed`. Recorded with its parent, never retrieved.

**Entity reference** *(Funes)* — a typed, namespaced opaque identifier for a project, identity,
repository, organization, or domain. It is portable across hosts and never a Landing integer ID.

**Source-scope association** — an explicit relation from a connector installation or one of its
streams to an entity reference, with confirmed, ambiguous, rejected, or superseded state. No active
association projects as unassigned.

**Run completeness** — `incomplete`, `partial`, or `complete`, separate from operational status so
a stopped run never masquerades as a full observation of its source.

**Legacy run reference** — the namespaced identity of an application-owned run before migration.
It deterministically maps to one package ULID and remains visible in the portable read model.

**Ingestion attempt** — one numbered execution inside a logical ingestion run. Retries add attempts
without changing the run identity or duplicating accepted-history references.

**Run read model** — a presentation-neutral projection of one run, its attempts, diagnostics, and
next safe action. Hosts consume it instead of querying Aleph tables.

**Domain ingestion run** — a DNS-specific projection over the shared ingestion run. It adds stable
account/domain scope and provider reconciliation references without defining another lifecycle.

**Manual launch** — an authorized, idempotent request to create or resolve a pending ingestion run
before a host dispatch adapter receives it.

**Authorization decision reference** — a stable host-issued reference proving which evaluated
decision allowed a manual run. It is evidence, not an Aleph-owned user or role policy.

**Envelope metadata assertion** — a versioned Funes metadata record. `aleph:envelope` carries
universal envelope attributes; `aleph:extension/<namespace>` carries one connector extension and
retains the Funes provenance that asserted it.
