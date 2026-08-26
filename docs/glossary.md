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

**Discovery origin** — `seed` or `link`. Preserved because FUNES-008 requires seeded and
link-discovered resources to be distinguishable.

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
