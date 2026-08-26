# Assumptions register

## A-001 — AHSD runs SchoolMessenger Presence

Confirmed from the district site footer, consistent with ALEPH-011. Calendar transport is expected to
be a SchoolMessenger endpoint rather than static HTML.

## A-002 — AHSD uses one subdomain per school

Confirmed: `ahsd.org`, `hs.ahsd.org`, `ms.ahsd.org`, `cse.ahsd.org`, `wav.ahsd.org`. This is why the
`ahsd` source allows `*.ahsd.org` rather than a single host, and why "newly discovered subdomains
enter the frontier" is a load-bearing acceptance criterion rather than a hypothetical.

## A-003 — Funes is the content-history boundary

Aleph depends on Funes' executable `ObservationStore` contract. Retrieved content, canonical resource
identity, and discovery relationships cross that boundary before Aleph marks a candidate fetched.

## A-004 — A single-process sequential crawl is sufficient

AHSD is a five-site district, bounded at 200 pages and depth 3. No measurement justifies concurrent
fetching, so the effective concurrency ceiling is one.

## A-005 — Public pages only

The crawler retrieves public resources by GET and HEAD. It does not authenticate, and login and
account paths are excluded by configuration in addition to the structural method bar (D-005).
