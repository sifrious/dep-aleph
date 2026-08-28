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

**Ingestion run** — one bounded execution of a declared capability against a source. A web crawl has
its own frontier. Runs may be `running`, `interrupted`, or `completed`; `--fresh` starts a new one.

**Capability** — an operation Aleph can execute. The implemented set contains only `web.crawl`.

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
