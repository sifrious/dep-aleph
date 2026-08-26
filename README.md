# Aleph

Aleph is named after the impossible point in Jorge Luis Borges's story *The Aleph*: a single place from which every other place can be seen at once, without losing its position or identity.

This package applies that idea to software systems. It gives Laravel applications a consistent way to observe many external sources, collect their history, and preserve where each record came from. Instead of building a separate collection pipeline for every API, repository, mailbox, document store, or activity stream, an application can use one ingestion model with source-specific connectors.

## The problems Aleph solves

Connecting to an external service is usually the easy part. Keeping the connection reliable over time is the real work.

An ingestion system must answer questions such as:

- How is an initial historical import different from a routine incremental sync?
- Where should a long-running import resume after a worker stops?
- How can the same page, event, or webhook be processed twice without creating duplicates?
- Which source accounts are healthy, stale, rate-limited, or missing credentials?
- What happened during a failed run, and is it safe to retry?
- How can records be normalized without discarding provider-specific identifiers and metadata?
- How can a new connector support only the operations that make sense for its source?

Aleph provides the shared machinery for answering those questions.

## What Aleph is

Aleph is a Laravel package for building ingestion and observation applications. It coordinates work; connectors remain responsible for understanding their sources.

Its core model covers:

- connector registration and capability discovery;
- multiple accounts for the same provider;
- source streams and their project, identity, repository, or domain scopes;
- scheduled, manual, webhook-triggered, and reconciliation runs;
- queued background execution;
- runs, attempts, partitions, leases, and heartbeats;
- typed, versioned checkpoints;
- retries, resumability, and failure classification;
- idempotent submissions and duplicate prevention;
- connector health and source freshness;
- raw source metadata and provenance;
- deterministic normalization before records are accepted by an application's history store;
- downloadable files, extracted content, and other produced artifacts;
- optional agent-assisted processing for sources that cannot be handled reliably with deterministic code.

## A connector is a set of capabilities

Aleph does not require every connector to implement one large interface. A local Git connector, for example, should not need placeholder methods for OAuth or webhooks. A webhook-only connector should not pretend it can backfill history.

Connectors declare the small capabilities they actually support, such as:

- discovering sources;
- importing historical records;
- synchronizing incrementally;
- polling for changes;
- consuming webhooks;
- downloading artifacts;
- extracting content;
- normalizing records;
- checking health;
- using agents for bounded processing.

The application can then expose, schedule, and run only valid operations.

## The ingestion lifecycle

A typical ingestion follows the same traceable sequence:

1. A schedule, user action, or webhook requests an operation.
2. Aleph creates an ingestion run with the source, capability, parameters, and trigger.
3. A worker claims an attempt or partition with a time-bounded lease.
4. The connector reads from its source using the last committed checkpoint.
5. Connector-specific normalization produces versioned candidate records while retaining source IDs and raw metadata.
6. The application accepts or rejects those records through its configured history writer.
7. Aleph commits the next checkpoint only after acceptance succeeds.
8. The run records its outcome, counts, warnings, errors, and remaining work.

This ordering makes interruption safe: accepted records are durable before progress advances.

## Backfills and incremental synchronization

Aleph treats historical import and incremental synchronization as related but distinct operations.

A backfill may define a time range, scope, partitions, rate limits, and an explicit completion boundary. An incremental sync begins from a committed cursor, timestamp, sequence, hash, or source revision. Connectors own the meaning and serialization of their checkpoint values; Aleph owns when those values may be committed.

Periodic reconciliation can repair edits, deletions, or events that a provider's incremental mechanism did not guarantee.

## Reliability and observability

Queue job state alone is not enough to explain ingestion. Jobs are an execution mechanism; ingestion runs are the durable domain record.

Aleph keeps logical runs and attempts separate so an operator can see:

- what was requested;
- what source and stream were involved;
- how the run was triggered;
- which checkpoint it started from;
- what each attempt processed and accepted;
- whether work completed, partially completed, failed, or was interrupted;
- whether a failure is retryable;
- what remains to be processed;
- when the source last synchronized successfully;
- whether the source is currently fresh and healthy.

## Provenance and idempotency

Every candidate record should retain enough information to identify its origin:

- source account and stream;
- provider object or event ID;
- provider revision, sequence, timestamp, ETag, hash, or commit identifier;
- source URL or path when available;
- occurrence and observation times;
- raw payload reference and payload hash;
- schema and normalizer versions;
- ingestion run and attempt.

These values provide stable idempotency keys and make later corrections explainable. Replaying a page, webhook, partition, or complete backfill should produce the same accepted identities rather than parallel copies.

## Source health

Aleph distinguishes application health from connector health. A worker can be running while a source account is unusable.

A connector may report checks for:

- authentication and token expiry;
- API reachability;
- provider rate limits;
- checkpoint and synchronization freshness;
- webhook delivery;
- queue backlog;
- storage availability;
- incomplete or repeatedly failing runs.

Health results are designed for dashboards, alerts, and automation without requiring an operator to inspect logs.

## Intended package boundaries

Aleph owns operational ingestion concerns: connector installations, credentials, schedules, runs, attempts, checkpoints, leases, health checks, webhook deliveries, and submission results.

Connector packages own provider communication, pagination, provider-specific checkpoint values, payload mapping, and their tests.

The consuming application owns the accepted historical model and supplies the writer that receives normalized candidates. This keeps Aleph useful whether an application stores events, documents, activity records, audit data, or another form of history.

## Current status

Aleph is under active design. The public contracts and installation instructions will be documented as the first executable vertical slice is implemented.

The first release should prove one complete workflow:

- install a connector;
- configure a source account;
- run a historical import;
- persist accepted records through an application-provided writer;
- commit checkpoints safely;
- continue with incremental synchronization;
- inspect status and retry a failed attempt.

Until that slice exists, this README describes the intended scope rather than a stable public API.
