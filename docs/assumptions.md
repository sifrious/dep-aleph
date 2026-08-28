# Assumptions register

## A-001 — AHSD runs SchoolMessenger Presence and embeds calendar artifacts

Confirmed from the district site footer. The Waverly calendar page currently embeds its PDF artifact
through a Google Drive iframe, so the artifact is external evidence rather than an allowed-host crawl
candidate. Provider-specific calendar transport remains separate from mechanical extraction.

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

## A-006 — Calendar-like paths are what the captures showed, and nothing more

`calendar_signals` for the `ahsd` source is `*calendar` and `*calendar/*`. Every configured pattern
traces to a captured request in `docs/investigations/schoolmessenger.md` (F-5). The recalled
SchoolMessenger convention of a platform module path was captured and disproved, which is why the
patterns are configuration rather than code (D-019).

## A-007 — SchoolMessenger calendar event data is out of reach, not merely unbuilt

Confirmed by capture (F-2, F-3, F-4): calendar pages ship a client-side component and no events, and
`robots.txt` disallows every service path such a component would call. This is a policy boundary
Aleph enforces, not a missing feature. Calendar artifacts still reach Aleph as PDF embeds (F-6).
