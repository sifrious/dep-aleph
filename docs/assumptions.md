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

## A-008 — Existing Landing associations are migration inputs, not portable identities

Confirmed by characterization of `Project`, `ProjectPath`, `ProviderAccount`, `Domain`,
`SlackContact`, and `GithubContributor`: useful links exist, but several terminate in Landing
foreign keys or inferred path matches. Importers must translate only confirmed stable provider or
Funes references; ambiguous candidates remain ambiguous.

## A-009 — Landing `SyncRun.steps` is source-backed checkpoint data

Characterization confirms that `steps` is an ordered repository-pipeline tree used by the subway
UI, while live per-item counts may come from Laravel job batches. Aleph preserves the stored tree
and current step as an opaque checkpoint; it does not claim that those values form a universal
connector lifecycle.

## A-010 — Hosts schedule retries but do not decide lifecycle semantics

Laravel workers and schedulers determine when an eligible attempt runs. Aleph determines whether a
run may start, resume, retry, fail, or complete and exposes the next safe action. No current package
requirement justifies owning queue transport or retry delays.

## A-011 — Domain migration requires externally supplied stable references

Landing `provider_account_id` and `domain_id` are local foreign keys. The domain-run adapter accepts
stable provider-account and domain references from the migration reconciler and never translates
those integers or the run label into portable identity.

## A-012 — Hosts evaluate manual-launch policy

The package receives a granted or denied decision with stable actor and decision references. The
host remains responsible for authenticating the caller and evaluating ownership or role policy;
Aleph validates and records the result but does not recreate each host's authorization system.

## A-013 — Queue adapters enforce the supplied dispatch policy

Aleph owns queue classification, priority, tags, and per-installation concurrency/rate budgets. The
host adapter applies those values to its runtime and returns the runtime job identity. The contract
tests exercise that behavior with an in-memory runtime so package semantics do not depend on
Laravel queue tables or Horizon.

## A-014 — Connectors own checkpoint serialization

Aleph treats checkpoint bytes as opaque and preserves a connector-supplied format and serializer
version. Monotonic streams additionally supply a non-negative numeric position that Aleph can
compare without parsing provider values. Replace-mode streams make no ordering claim.
