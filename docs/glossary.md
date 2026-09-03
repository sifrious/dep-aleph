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

**Redirect alias.** A requested canonical URL that accepted evidence has shown resolves to another
canonical URL. Later runs retain the alias as a skipped frontier row and fetch the proven final URL
once. Similar spelling, a trailing slash, or a `www` prefix does not create an alias by itself.

**Discovery origin** — `seed`, `link`, `iframe`, or `embed`. Preserves how a resource entered the
operational frontier.

**Discovery relationship** — the typed Funes edge from one observed HTML resource to a canonical
resource discovered through an anchor, iframe, or embed.

**Mechanical extraction** — a deterministic, versioned derivation from preserved response bytes.
Current classifications are HTML, PDF, and unsupported.

**Document format handoff.** This step runs after Aleph accepts a Google Drive export. It sends the
accepted bytes to a formatter and records derived text against the original Funes observation.
The bundled formatter supports DOCX, XLSX, PPTX, PDF, Markdown, CSV, and plain text. Other formats
remain deferred. See `src/Connector/GoogleDrive/DocumentFormatHandoff.php` and
`src/Connector/GoogleDrive/FunesDocumentFormatHandoff.php`.

**Google Drive source.** A configured Drive account or shared drive. Aleph stores its stable host
identifier and an opaque OAuth2 reference. The host-bound file client resolves credentials when it
downloads or exports a file. See `src/Connector/Configuration/GoogleDriveConfigurationAdapter.php`.

**Slack activity source.** A host-registered reader for one Slack workspace. The live implementation
uses the Web API through an opaque token resolved by the host. It returns provider-neutral users,
channels, and messages to Aleph. See `src/Connector/Slack/SlackWebApiActivitySource.php`.

**GitHub activity source.** A reader for repository and collaboration history. The bundled GraphQL
implementation resolves a token through the source installation and maps provider nodes to canonical
repository, pull request, review, comment, and reaction activities. See
`src/Connector/GitHub/GitHubGraphqlActivitySource.php`.

**Linear activity source.** A reader for workspace projects, issues, milestones, updates, reports,
tasks, and links. The bundled HTTP transport resolves an OAuth token or personal API key for one
source installation before each GraphQL request. See `src/Connector/Linear/LinearGraphqlSource.php`.

**Gmail mailbox source.** A configured Gmail mailbox read through OAuth. The bundled source lists and
fetches full messages on its first sync, then reads mailbox history after the last accepted Gmail
history ID. The checkpoint also retains provider page tokens when a bounded run pauses. See
`src/Connector/Email/GmailApiSource.php` and `src/Connector/Email/GmailCheckpoint.php`.

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

**Entity reference** *(Funes)* — a typed, namespaced opaque identifier for a project, site, identity,
repository, organization, or domain. It is portable across hosts and never a Landing integer ID.

**Source-scope association** — an explicit relation from a connector installation or one of its
streams to an entity reference, with unassigned, confirmed, ambiguous, rejected, or superseded state.

**Domain reconciliation** — an operator or authorized-process decision relating one observed domain
stream to zero or more stable project, site, and repository references without name-based inference.

**Run completeness** — `incomplete`, `partial`, or `complete`, separate from operational status so
a stopped run never masquerades as a full observation of its source.

**Legacy run reference** — the namespaced identity of an application-owned run before migration.
It deterministically maps to one package ULID and remains visible in the portable read model.

**Ingestion attempt** — one numbered execution inside a logical ingestion run. Retries add attempts
without changing the run identity or duplicating accepted-history references.

**Run read model** — a presentation-neutral projection of one run, its attempts, diagnostics, and
next safe action. Hosts consume it instead of querying Aleph tables.

**Recovery action** — the machine-readable next safe action for a run: start, resume, retry,
restart, provide credentials, require user action, or none.

**Ingestion failure** — an immutable timeline event attached to a run and optional attempt. It
retains domain or queue origin, category, partition, details, and occurrence time.

**Retry lineage** — the link from a new numbered attempt to the failed or partial attempt it
reconstructs. The child retains the reason, optional partition, durable checkpoint, and queue policy.

**Domain ingestion run** — a DNS-specific projection over the shared ingestion run. It adds stable
account/domain scope and provider reconciliation references without defining another lifecycle.

**Manual launch** — an authorized, idempotent request to create or resolve a pending ingestion run
before a host dispatch adapter receives it.

**Authorization decision reference** — a stable host-issued reference proving which evaluated
decision allowed a manual run. It is evidence, not an Aleph-owned user or role policy.

**Envelope metadata assertion** — a versioned Funes metadata record. `aleph:envelope` carries
universal envelope attributes; `aleph:extension/<namespace>` carries one connector extension and
retains the Funes provenance that asserted it.

**Queued ingestion unit** — one durable pending ingestion attempt plus its runtime-neutral queue
class, priority, tags, and concurrency/rate policy. A host queue job transports the unit but does not
own its history.

**Attempt heartbeat** — the latest durable proof that a worker is executing an ingestion attempt.
Crossing the host-selected timeout boundary turns the attempt into a retryable failure.

**Source stream** — a stable, enableable unit inside one source installation, optionally scoped to
a provider object. Checkpoints are isolated by stream, capability, and partition.

**Accepted-through checkpoint** — an append-only checkpoint commit whose Funes references state
exactly which immutable observations were accepted before the connector-owned cursor advanced.

**Ingestion continuation** — the latest accepted-through checkpoint for one stream, capability,
and partition, reconstructed with an explicit record, runtime, and partition budget.

**Continuation lease** — an expiring exclusive claim on one stream capability partition. It keeps
two workers from resuming the same range while allowing safe takeover after expiry.

**Source-stream freshness** — the provider-neutral projection of last attempt, last successful run,
accepted-through time, next due time, and current healthy, due, stale, or never-synchronized state.

**Freshness expectation** — a per-stream expected interval and stale boundary. The package query
applies it to the caller's clock so freshness cannot freeze at the value last written.

**Connector health check** — an expiring, immutable result for one installation dimension:
configuration, authentication, reachability, rate limit, freshness, webhook, backlog, queue, Funes,
or storage.

**Health remediation** — a machine-readable code and plain-language next step attached to an
unhealthy, degraded, unknown, or expired health dimension.

**Ingestion schedule** — a host-independent recurring operation for one source installation and a
capability its connector advertises, with cron cadence, timezone, constraints, and next due time.

**Schedule dispatch** — the immutable link from one schedule occurrence to the ingestion run it
created. Its schedule/due-time identity prevents duplicate dispatch across scheduler hosts.

**Backfill range** — two connector-owned boundary values with an explicit format. A backfill cannot
start without both bounds.

**Ingestion partition** — one deterministic ordered slice of a backfill run, retaining cumulative
processed, accepted, and failed counts plus its resumable checkpoint and lifecycle.

**Incremental strategy** — how one source stream detects change: cursor, high water, webhook, hash,
or full reconciliation. Webhook, hash, and reconcile strategies require periodic reconciliation.

**Incremental change** — one Funes-accepted added, updated, or deleted source event with a stable
provider change ID and fingerprint. Unchanged polls create no change row.

**Source account** — one independently enabled installation of a connector, identified by its
provider account ID and stable Funes source-account reference. Its streams and operations are scoped
by the installation ID.

**Connector credential** — encrypted account-specific authentication material addressed through an
opaque reference. Its non-secret operational metadata includes credential kind, scopes, expiry, and
refresh time; secret material never appears in its metadata projection.

**Package boundary gate** — executable validation that one independent connector can cross Aleph's
public observation contract into Funes while dependency direction, provider isolation, replay safety,
provenance, health separation, and the default read-only surface remain intact.

**Git repository source** — replaceable reader that supplies a stable repository identity and a
captured ref snapshot without exposing local paths, GitHub SDK values, or host database models.

**Git ref movement** — an observation that one named ref moved from a previous SHA to a new SHA. It
records broken ancestry explicitly so a force-push remains distinct from ordinary advancement.

**Git file change** — an added, modified, deleted, or renamed path derived by comparing two trees.
Deletion retains the prior blob SHA; rename retains both paths and the shared blob SHA.

**GitHub ingestion run** — the GitHub projection of a provider-neutral ingestion run. It adds
account, repository, repository-watch, target, reconciliation, and all/project/repo/pull-request/watch
scope without owning GitHub historical records.

**GitHub activity** — a canonical repository, pull-request, review, comment, reaction, or contributor
revision identified by repository coordinates, provider node ID, and provider update time. Polling
and webhook delivery are observation transports, not separate historical identities.

**GitHub webhook delivery** — one signature-verified encrypted payload identified within a source
installation by GitHub's delivery ID. Its processed state retains the accepted Funes references so
replay does not repeat provider history.

**Repository watch** — package-owned orchestration state that observes one stable repository through
polling, webhooks, or both. It retains cadence, checkpoint, head, due, completion, enablement, error,
and backoff without owning repository history.

**Repository watch trigger** — one provider-neutral change identity claimed within a repository watch.
Its uniqueness coalesces webhook and poll observations before duplicate ingestion work is launched.

**Repository watch signal** — a provider-neutral poll, webhook, or reconciliation observation naming
one repository ref and head. The watch/ref/head tuple, rather than its transport delivery ID, defines
the incremental-run identity.

**Shell command observation** — a human or agent command tied to a stable source record/revision and
host, user, shell, cwd, session, environment, time, duration, exit, and output context.

**Shell redaction decision** — the retained or redacted result of applying a named policy before Funes
acceptance. Redacted payloads keep an original command hash and reasons but no original secret text.

**AI conversation** — one provider session normalized to a stable provider ID, source revision, raw
reference, provider metadata, and an ordered graph of neutral messages.

**AI message part** — one ordered text, thinking, tool-use, tool-result, or provider-specific block
that retains its raw provider structure beside neutral text and tool-call linkage.

**Linear ingestion run** — the Linear projection of a shared ingestion run, adding workspace/project
scope, provider run and reconciliation IDs, targets, and per-stream cursors without owning history.

**Linear activity** — a canonical project, issue, milestone, update, report, task, or link revision
identified by workspace, resource kind, Linear ID, source update time, and provider payload.

**Linear stream checkpoint** — an accepted-through cursor isolated to one independently paginated
Linear workspace stream.

**F28 email message** — a provider-neutral email revision retaining provider and RFC identity,
threading, original headers and addresses, normalized participants, timestamps, bodies, mailbox
state, attachment references, and raw-source provenance.

**Email provider checkpoint.** Versioned Gmail sync state, a Microsoft Graph delta link, or an IMAP
UIDVALIDITY and UID value committed only after Funes accepts the preceding page.

**Digory attachment handoff** — a stable historical reference to provider-owned attachment content;
Aleph retains identity and metadata while artifact retrieval and storage remain Digory concerns.

**Slack credential handle** — workspace-scoped non-secret metadata plus an opaque reference resolved
only by the host secret store; token values never enter Aleph portable persistence or Funes.

**Slack credential state** — the explicit active, expired, revoked, or missing availability of one
workspace credential at a given time.

**Slack ingestion run** — the Slack projection of a shared ingestion run, adding workspace/channel
scope, provider run/reconciliation references, targets, and continuation checkpoint state without
owning Slack history.

**Slack activity** — a canonical workspace, user, channel, message, file, or link revision shared
by polling and Events API transports.

**Slack cursor/high-water checkpoint** — one accepted-through partition value carrying an in-page
continuation cursor and the newest completely observed provider timestamp.

**F28 communication record** — a provider-neutral communication revision retaining its source,
conversation, provider identity and revision, participants, body, relationships, reactions,
attachments, lifecycle change, timestamps, reconciliation metadata, and raw-source provenance.

**Communication provider checkpoint** — an opaque provider cursor committed only after the page it
follows has been accepted by Funes.

**Unsupported communication update** — an explicit F28 record preserving the provider update and
provenance when Aleph cannot yet interpret its message-specific content.

**SMS source identity** — a stable identity derived from the exact provider or device address; it
retains original and normalized forms but makes no claim about the person controlling the address.

**Unavailable communication object** — explicit source evidence that a provider channel, thread, or
message no longer exposes content; absence is retained as lifecycle state instead of a missing row.

**Historical assertion adapter** — a provider registration that converts one external claim payload
into a canonical Funes observed, declared, or inferred assertion. It retains the raw provider record
as a cross-package provenance reference.

**Assertion normalization report** — the canonical assertion plus its raw-source reference,
provider fields that Aleph could not represent, and optional provider confidence. This is mapping
evidence, not canonical Funes state.

**Operator command.** An Artisan transport over an Aleph application service. It parses input and
renders text or JSON, but it does not implement ingestion, retry, resume, or provider behavior.

**Source installation state.** The read model returned for one configured source. It combines the
installation, current health report, active streams and checkpoints, schedules, recent runs, and
active retry backoff without copying those records into another table.
