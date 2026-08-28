# Historical writers inventory

Answers step 1 of MME-841. Every place that creates or updates a durable record, classified by
whether it is *accepted historical truth* (must pass through Funes acceptance), *operational
state* (Aleph's own business), or a *projection* (a read-optimised copy of something Funes owns).

Only the first class is in scope for the acceptance boundary. Persisted is not the same as
historical.

## Aleph package — `~/Projects/Packages/Aleph`

| Writer | Writes | Meaning | Funes target |
| --- | --- | --- | --- |
| `Web/FunesObservationWriter` | `funes_*` via `AcceptanceClient` | historical | **migrated** — observations + discoveries + extractions |
| `Envelope/EnvelopeSubmitter` | `funes_*` via `AcceptanceClient` | historical | **migrated** — the connector-facing entry point |
| `Acceptance/Backfill` | `funes_*` via `AcceptanceClient` | historical | **migrated** — legacy rows, same contract |
| `Acceptance/Submissions` | `aleph_funes_submissions` | operational | none — records that Aleph *attempted*, not what is true |
| `Normalization/NormalizationAttempts` | `aleph_normalization_attempts` | operational | none — append-only lineage, evidence for a candidate |
| `Normalization/NormalizationCache` | cache store | cache | none — derivable, version-keyed, disposable |
| `Ingestion/IngestionRuns` | `aleph_ingestion_runs` | operational | none — run bookkeeping |
| `Web/Frontier` | `aleph_frontier_candidates` | projection + operational | crawl state; holds `observation_id` returned by Funes |
| `Connector/ConnectorInstallations` | `aleph_connector_installations` | operational | none — operator configuration (D-035) |
| `Inventory/*` | none (reads only) | — | reads `funes_observations` for freshness |

`aleph_frontier_candidates` is the one mixed row: the crawl needs its own state machine (pending,
claimed, fetched, skipped) *and* stores the accepted `observation_id` and disposition Funes
returned. It references Funes identity rather than asserting its own — which is what step 9 asks
for.

## Aleph application — `~/Projects/aleph`

No direct historical writers. The application hosts queues, scheduling and health endpoints only.

## Landing — `~/Projects/landing` (reference implementation, not edited)

The six writers MME-841 names, plus what a sweep of `app/` turned up.

| Writer | Writes | Meaning | Funes target |
| --- | --- | --- | --- |
| `Services/ClaudeHistoryIngestor` | `ClaudeProject`, `ClaudeSession` | historical | events + observations |
| `Services/SlackSync` | `Channel`, `SlackMessage`, `SlackUser`, `SlackContact`, `SlackFile` | historical (messages, files); identity (users, contacts); operational (channels) | events, artifacts, identities |
| `Services/LinearSync` | `LinearIssue`, `LinearProjectUpdate`, `LinearMilestone`, `LinearTask` | historical | events + provenance |
| `Services/LinearSync` | `Task` | operational | none — Landing's own planning record |
| `Services/GithubSync` | `PullRequest`, `PrReview`, `PrReviewComment`, `PrIssueComment`, `PrReaction` | historical | events + provenance |
| `Services/GithubSync` | `Repo` | identity | sources / identities |
| `Artifacts/ArtifactSyncer` | `AgentArtifact` | historical | artifacts |
| `Research/ResearchIngestor` | `ResearchSource` | historical | observations + artifacts |
| `Research/ResearchIngestor` | `Topic` | operational | none — a curation vocabulary |
| ~80 `Http/Controllers/*` | assorted | operational / user-authored | none — the display layer never creates accepted history |

The controller sweep matters: 87 files in Landing create records, and all but the writers above
are user-driven CRUD. Migrating on file count would have been wrong. Migrating on *meaning* leaves
six connectors, of which this ticket moves one representative path.

## Deliberately not migrated here

Per the ticket's "do not migrate every connector", the Landing connectors stay where they are.
They are follow-up tickets, listed in `docs/decisions.md` under D-051.
