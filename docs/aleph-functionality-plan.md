# Aleph functionality plan

## Goal

Make Aleph usable as a complete ingestion control plane from a Laravel host. An operator should be
able to declare a source, inspect available connectors, launch supported work, monitor it, recover a
failed run, and verify what Funes accepted without writing application-specific orchestration code.

The plan extends the capability contracts already in the package. It does not add another execution
framework or move historical storage out of Funes.

## Current baseline

Aleph already has contracts for source configuration, discovery, backfill, incremental sync,
webhooks, artifact downloads, extraction, normalization, health checks, and agent-assisted work. It
also has durable runs, attempts, checkpoints, retry history, scheduling policy, connector manifests,
and the Funes acceptance boundary.

Operator commands now cover connector discovery, source configuration and state, durable launches,
schedule dispatch, recent runs, inspection, retry, and resume. Google Drive preserves downloaded
bytes and records text from DOCX, PDF, Markdown, CSV, and plain-text files as Funes extractions. XLSX
and PPTX remain explicit deferred results. Slack has a Web API polling adapter that resolves opaque
host credentials, resumes bounded pages from accepted checkpoints, and converges with verified
webhooks on canonical activity identity. The crawler records accepted redirect aliases and uses them
to avoid duplicate retrievals on later runs while keeping both requested and final URLs visible.

## Milestone 1: operator-facing capability commands

Add thin Artisan commands over existing application services.

1. Add `aleph:connectors` to list connector IDs, declared capabilities, configuration fields, and
   whether each connector is enabled.
2. Add `aleph:source:configure` to call `Connector\Configuration\ConfigureSource`. Accept an opaque
   credential reference, never credential material. Support JSON output for host automation.
3. Add `aleph:run` to dispatch one capability through `ConnectorDispatcher`. Validate the request
   shape before creating a run.
4. Add `aleph:runs` and `aleph:run:show` to expose status, attempts, checkpoints, accepted references,
   remaining work, and the safe recovery action.
5. Add `aleph:run:retry` and `aleph:run:resume` over the existing retry and resume services.

Completion checks:

- Every command delegates business behavior to an existing application service.
- Text and JSON output contain the same facts.
- Invalid connector IDs, unsupported capabilities, unsafe retries, and inline secrets fail before
  dispatch.
- Feature tests cover success, rejection, replay, and JSON output.
- `docs/connectors.md`, `docs/glossary.md`, and command examples describe the current behavior.

## Milestone 2: source lifecycle and health

Turn configured sources into something an operator can manage without database access.

1. Add source listing and inspection through a package read service.
2. Add enable and disable operations for connector installations.
3. Expose connector health evidence and its expiry.
4. Show schedule eligibility, active backoff, checkpoint position, and last successful acceptance.
5. Add a scheduler entry point that selects due work and passes it to the host queue. Keep queue
   ownership in the host application.

Completion checks:

- Source state can be inspected without reading package tables directly.
- Health checks never create historical records in Funes.
- Disabled sources and active backoff prevent dispatch.
- Scheduler tests use a fake clock and do not require Laravel Horizon.
- Configuration and environment references are updated for every new key.

## Milestone 3: complete the document-format handoff

Replace Google Drive's default deferred handoff with one real formatter integration.

1. Define the smallest formatter contract supported by the current DOCX, XLSX, PPTX, PDF, CSV, and
   Markdown interchange files.
2. Bind one implementation in the host or a dedicated package. Do not put format-specific parsing
   policy into the Google Drive connector.
3. Preserve the original Drive artifact and record the derived document with its formatter identity,
   version, checksum, and source relationship.
4. Make replay idempotent by input checksum and formatter version.

Completion checks:

- A Google Doc export produces accepted original and derived records.
- A PDF that needs no conversion follows the same contract without being wrapped again.
- Missing formatter support remains an explicit deferred result.
- Formatter failure leaves a retryable Aleph attempt and no invented successful derivation.

## Milestone 4: finish one live provider path

Choose one provider with existing Aleph contracts and complete configuration, health, backfill,
incremental sync, and webhook convergence. Slack is the best first candidate because source
configuration and communication normalization already exist.

1. Resolve the opaque credential reference through a host-owned credential provider.
2. Implement or connect Slack history retrieval behind the existing small capability contracts.
3. Persist cursor and high-water checkpoints only after Funes acceptance.
4. Feed verified webhook events and polling results through the same canonical activity identity.
5. Add bounded contract tests with recorded provider fixtures. Keep live credentials out of the
   repository and normal CI.

Completion checks:

- Backfill can stop at a bound and resume without duplicates.
- Incremental sync advances its checkpoint only after acceptance.
- Webhook and polling overlap produces one historical effect.
- Rate limits and retry hints remain observable without putting provider policy into Funes.
- The connector passes the shared conformance suite and a separate opt-in live smoke test.

## Milestone 5: identity convergence for redirects

Address the measured crawler duplication described by Q-001 and Q-007 in
`docs/open-questions.md`.

1. Record that two requested canonical URLs resolved to the same final resource.
2. Prevent later runs from fetching both aliases when prior accepted evidence proves the mapping.
3. Keep requested identity and redirect provenance queryable.
4. Do not apply a global trailing-slash or `www` rewrite.

Completion checks:

- Bare-domain and `www` aliases converge after observed redirects.
- File paths and hosts without observed equivalence remain distinct.
- The frontier stays deterministic across replay and resume.
- Inventory reports the requested URL, final URL, and alias decision.

## Milestone 6: package readiness

Finish the work needed for routine use by another Laravel application.

1. Add an installation and first-run guide that starts with source configuration and ends with an
   accepted Funes observation.
2. Add an upgrade check for migrations, configuration changes, and connector contract changes.
3. Run the package suite against the supported PHP and Laravel matrix.
4. Add opt-in smoke tests for external tools such as `yt-dlp`, media inspection, and format workers.
5. Publish a connector support table that distinguishes contract support, fixture verification, and
   live verification.

Completion checks:

- A fresh Laravel application can install Aleph, configure one source, run ingestion, and inspect the
  result using documented commands.
- CI runs unit, feature, static-analysis, formatting, and dependency-boundary checks.
- Optional binaries fail with clear diagnostics and do not break unrelated connectors.
- Documentation contains no obsolete command or architecture descriptions.

## Execution order

Complete milestones 1 and 2 first. They expose and test the mechanisms every connector needs.
Milestone 3 then proves a deferred cross-package handoff. Milestone 4 proves one full provider
lifecycle. Milestone 5 should wait for a crawler fixture that demonstrates the alias behavior.
Milestone 6 runs throughout the work and closes after the other milestones.

Each milestone should land as a reviewable branch. Do not combine operator commands, a live provider,
and redirect identity changes in one pull request.

## Non-goals

- Building a second queue system inside Aleph.
- Storing credentials or provider tokens in Funes.
- Moving application policy into connector contracts.
- Adding provider write, update, or delete operations.
- Creating one large connector interface that every provider must implement.
- Adding concurrency until a measured workload requires it.
- Expanding every launch adapter before one provider lifecycle works end to end.
