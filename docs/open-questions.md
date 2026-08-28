# Open questions

## Q-001 — Trailing-slash normalization

`ahsd.org/robots.txt` responds `301` to `.../robots.txt/`. SchoolMessenger appends trailing slashes,
so `/page` and `/page/` are the same resource but two canonical URLs, costing one redirect each.

Forcing a trailing slash in `UrlCanonicalizer` is wrong in general — it breaks `/file.pdf`. Funes
identity uses the canonical final URI, but Aleph does not yet collapse two frontier entries that
redirect to that same resource.

## Q-002 — The `query_parameters` allowlist for AHSD remains empty

Every query parameter is currently dropped for the `ahsd` source. ALEPH-009 confirmed that the
Waverly PDF iframe identity does not need query parameters. Pagination and SchoolMessenger calendar
range parameters remain the likely exceptions, but none has yet demonstrated stable resource
identity sufficient to enter the allowlist.

## Q-003 — Percent-encoding is not normalized

`UrlCanonicalizer` does not normalize percent-encoding case or decode unreserved characters, so
`/a%2Fb` and `/a%2fb` are distinct canonical URLs. No observed AHSD behaviour needs it. Revisit if
duplicate frontier retrievals become measurable.

## Q-004 — Internationalized hostnames are not punycoded

Hosts are lowercased but not IDN-normalized. Irrelevant for AHSD; relevant if Aleph crawls a source
with non-ASCII hosts.

## Q-005 — Does the hand-rolled robots parser stay?

See D-006. It stays until Aleph needs sitemap discovery or strict RFC 9309 conformance.

## Q-007 — Bare domain versus `www`

Verified live: `https://ahsd.org/` redirects to `https://www.ahsd.org/`, while the four school
subdomains serve directly. The district seed now points at `www` so a redirect is not paid on every
run, and both hosts remain allowed.

They are still two canonical URLs for one resource. If a page links to the bare domain, the frontier
will hold both even though Funes resolves both retrievals to final-URI identity.

## Q-006 — Concurrency

The crawl is single-process and sequential; its concurrency ceiling is one and the per-host delay is
the rate control. Revisit only against a measured throughput need.

## Q-008 — District PDFs are stored under a CDN identity

Captured live: `https://ms.ahsd.org/UserFiles/Servers/Server_453577/File/…pdf` returns `301` to
`https://cdnsm5-ss20.sharpschool.com/UserFiles/…pdf`, which serves `application/pdf`. Because Funes
identity is the canonical final URL, every district PDF is stored under a `sharpschool.com` resource
reference, and `Crawler::baseFor()` then treats that base as an external host.

For PDFs this costs nothing — they yield no discoveries. It would matter if an HTML page redirected
to the CDN, because discoveries would not be extracted from it. The inventory keeps both identities
(`canonical_url` and `final_url`), so the divergence is queryable rather than hidden.

Options are to allow the CDN hosts, to record the pre-redirect URL as the resource reference, or to
leave it. None is justified by a measured failure yet. Related to Q-001 and Q-007.

## Q-009 — The staleness probe loads payloads it does not need

`InventoryReader` asks `ObservationStore::find()` whether a resource has any prior observation.
`find()` hydrates the whole observation, payload included, to answer a question about existence and
timing. At AHSD's 200-page bound this is not measurable, and most probes miss outright because a
pending candidate has usually never been fetched.

A payload-free summary read on `ObservationStore` would fix it, at the cost of widening another
package's contract for one consumer. Revisit when an inventory is large enough for the cost to show
up, not before.

## Q-010 — Which authority issues canonical project references?

The contract accepts namespaced stable references but does not declare whether a future Funes
entity catalogue, Linear project ID, or another package issues the canonical project identifier.
No current behavior requires choosing. Importers must retain their namespace and must not collapse
two references until an authorized reconciliation records that decision.

## Q-011 — Which Landing run families can share an installation identity during migration?

The generic `SyncRun` has repository and watch references but no connector-installation ID.
Provider-specific `GithubSyncRun`, `SlackSyncRun`, `LinearSyncRun`, and `DomainSyncRun` migrations
must resolve installations independently and must not infer an account from a display label. Their
model-catalogue tickets own that reconciliation.

## Q-012 — Where should provider retry timing live?

Aleph preserves retry classification and provider details such as `retry_after`, while the host
scheduler decides when to dispatch the next attempt. Revisit only if two hosts demonstrate
incompatible timing behavior that belongs in the package contract.
