# Open questions

## Q-001 — Trailing-slash normalization

`ahsd.org/robots.txt` responds `301` to `.../robots.txt/`. SchoolMessenger appends trailing slashes,
so `/page` and `/page/` are the same resource but two canonical URLs, costing one redirect each.

Forcing a trailing slash in `UrlCanonicalizer` is wrong in general — it breaks `/file.pdf`. Resolving
identity by final URI after redirect is ALEPH-007's job. Decide there whether Aleph should also
collapse the frontier entry.

## Q-002 — The `query_parameters` allowlist for AHSD is empty

Every query parameter is currently dropped for the `ahsd` source. That is correct until we know which
parameters address distinct resources. Populate it once ALEPH-009 has seen real pages — pagination
and calendar range parameters are the likely candidates.

## Q-003 — Percent-encoding is not normalized

`UrlCanonicalizer` does not normalize percent-encoding case or decode unreserved characters, so
`/a%2Fb` and `/a%2fb` are distinct canonical URLs. No observed AHSD behaviour needs it. Revisit if
duplicate observations appear in ALEPH-007.

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
will hold both. Collapsing them belongs to ALEPH-007's identity-by-final-URI mapping, alongside Q-001.

## Q-006 — Concurrency

The crawl is single-process and sequential; the per-host delay is the only rate control. Bounded
concurrency was specified in ALEPH-006 but is not needed at AHSD's size. Revisit only against a
measured need.
