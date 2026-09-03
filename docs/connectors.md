# Writing a connector

A connector is a composition of capabilities, not an implementation of one large interface.
Implement only what your source can actually do.

## Identity

Every connector implements `Connector`. This is identity and configuration metadata only — it
implies no ingestion behaviour.

```php
final class ArchiveDrop implements Connector
{
    public function id(): string { return 'archive-drop'; }
    public function name(): string { return 'Archive Drop'; }
    public function version(): string { return '2.1.0'; }

    public function configuration(): ConfigurationSchema
    {
        return new ConfigurationSchema(
            ConfigurationField::text('base_url', 'Root of the archive'),
            ConfigurationField::secret('api_token', 'Token used for downloads'),
        );
    }
}
```

`ConfigurationSchema` describes *what a connector needs*. It never carries values. A field marked
`secret()` is flagged as such in the manifest and its value lives wherever the application keeps
credentials — never here.

## Capabilities

Add a capability by implementing its interface. That is the whole registration step.

| Capability | Interface | Method | Dispatchable |
| --- | --- | --- | --- |
| `sources.configure` | `ConfiguresSources` | `configureSource` | no |
| `sources.discover` | `DiscoversSources` | `discoverSources` | yes |
| `history.backfill` | `Backfills` | `backfill` | yes |
| `sync.incremental` | `SyncsIncrementally` | `syncIncrementally` | yes |
| `webhooks.consume` | `ConsumesWebhooks` | `consumeWebhook` | yes |
| `artifacts.download` | `DownloadsArtifacts` | `downloadArtifact` | yes |
| `health.check` | `ChecksHealth` | `checkHealth` | yes |
| `content.extract` | `ExtractsContent` | `extractContent` | no |
| `records.normalize` | `Normalizes` | `normalize` | no |
| `agents.assist` | `UsesAgents` | `runAgentTask` | no |

`sources.configure` declares a source before any run exists, and the last three participate in a run
rather than being started on their own — see D-030.

Capability pages under `docs/capabilities/` document every input a group reads:
[`sources.configure`](capabilities/sources-configure.md).

There is no capability list to maintain. `CapabilitySet::of($connector)` derives capabilities from
the interfaces you implement, and the manifest is built from that, so the two cannot disagree.

## Registering

```php
app(ConnectorRegistry::class)->register(new ArchiveDrop);
```

## Dispatching

```php
$dispatcher = app(ConnectorDispatcher::class);

if ($rejection = $dispatcher->rejectionFor('archive-drop', Capability::Backfills)) {
    return response()->json($rejection->toArray(), 422);
}

$sources = $dispatcher->dispatch(
    'archive-drop',
    Capability::DiscoversSources,
    new OperationRequest('archive-drop:root'),
);
```

Check with `supports()` or `rejectionFor()` **before queueing**. `dispatch()` also refuses
unsupported work, throwing `UnsupportedCapability` whose `rejection` carries a machine-readable
reason, the connector id, the capability, and the list actually supported. A worker should never
be the first thing to discover a connector cannot do the job.

Rejection reasons: `unknown_connector`, `capability_not_supported`, `capability_not_dispatchable`.

The Artisan boundary uses the same catalogue and launch services:

```bash
php artisan aleph:connectors --json
php artisan aleph:run <installation-id> history.backfill slack:T123 \
    --idempotency=backfill-2026-09-03 \
    --actor=user:42 \
    --decision=authorization:884 \
    --parameter='channels=["C123"]'
```

`aleph:run` records the request through `LaunchIngestion` and delegates execution through the host's
`ManualIngestionDispatcher`. The command does not call a provider directly. Retry and resume commands
delegate to `RetryIngestion` and `ResumeIngestion`, which apply the same safety checks used by other
transports.

Source lifecycle commands use `SourceInstallationQueries` for one consistent read model:

```bash
php artisan aleph:sources --json
php artisan aleph:source:show <installation-id> --json
php artisan aleph:source:disable <installation-id>
php artisan aleph:source:enable <installation-id>
```

The detail response includes connector health, stream freshness, the latest accepted checkpoint for
each partition, schedules, recent runs, and active retry backoff. A due schedule is eligible only when
its installation and schedule are enabled, its claim lock is free or expired, and no attempt for the
same installation and capability has an active backoff.

## Google Drive document formatting

`LaunchGoogleDriveIngestion` stores the exported file as the accepted observation. It then passes the
same bytes to `Connector/GoogleDrive/DocumentFormatHandoff`. The default
`Connector/GoogleDrive/FunesDocumentFormatHandoff` records extracted text in `funes_extractions`; it
does not create a second observation.

`Connector/GoogleDrive/LocalDocumentFormatter` reads DOCX, XLSX, PPTX, PDF, Markdown, CSV, and plain
text. The Office formats require PHP's DOM and Zip extensions. XLSX extraction reads cell values,
including shared and inline strings, in worksheet order. PPTX extraction reads text in numbered slide
order. PDF parsing uses `smalot/pdfparser`.

Funes keys each extraction by the accepted observation, formatter name, and formatter version. An
exact replay returns the existing extraction ID. A different result for the same key fails instead of
rewriting history. Change `LocalDocumentFormatter::VERSION` whenever formatting behavior changes.

## Display layers

Ask the manifest; never encode provider knowledge in the UI.

```php
foreach (app(ConnectorRegistry::class)->manifests() as $manifest) {
    $actions[$manifest->id] = $manifest->availableOperations();
}
```

A discovery+download connector yields exactly `['sources.discover', 'artifacts.download']`, so a
backfill button is never rendered for it.

## Testing your connector

`Sifrious\Aleph\Testing\Contracts\ConnectorContract` ships with the package and returns a list of
violations rather than asserting, so it works under any test runner.

```php
it('conforms to the Aleph connector contract', function (): void {
    $connector = new ArchiveDrop;

    expect(ConnectorContract::violations($connector))->toBe([])
        ->and(ConnectorContract::probeAll($connector))->toBe([]);
});
```

`violations()` checks identity, configuration hygiene (including credential-shaped fields that are
not marked secret), and manifest agreement. `probeAll()` invokes every capability you declare with
a synthetic request and verifies the return type.

## GitHub activity adapters

`GitHubActivityConnector` owns configuration, backfill, incremental, and webhook behavior. The
bundled `GitHubGraphqlActivitySource` reads repository, pull request, review, comment, and reaction
records through `GitHubGraphqlTransport`. Its token resolver receives the source installation ID and
returns secret material only for the request. A consuming application registers the source and its
resolved webhook secret by source installation. Aleph owns cursor advancement, Funes submission,
signature validation, encrypted delivery replay, account isolation, and rate-limit failure evidence.
OAuth callbacks, token acquisition, and HTTP route handling stay in the consuming application.
The bundled source reads at most 100 reviews, comments, and reactions per pull request. It refuses a
truncated nested connection. A host that needs larger pull requests must register a source that
paginates those connections.

## Shell history adapters

`ShellHistoryConnector` accepts registered sources composed from `ZshHistoryAdapter`,
`AtuinHistoryAdapter`, or `ClaudeBashHistoryAdapter` observations. A source installation identifies
the host/user/shell boundary. The package applies `shell.secrets:1` before constructing a Funes
envelope, retains source revision and execution context, and makes exact rescans idempotent. Hosts own
filesystem and SQLite access; adapter raw metadata is not copied into immutable payloads.

## AI conversation adapters

`AiConversationConnector` accepts registered sources composed with `ClaudeConversationAdapter`,
`CodexConversationAdapter`, or `AlternateConversationAdapter`. Adapters normalize conversation and
message identity, chronology, authorship, parent/thread/branch links, sidechains, agents, and ordered
parts while retaining each provider record, block, and raw reference in the accepted envelope.
`AiConversationQueries` provides provider-neutral chronology, author, and ancestor-thread queries.
Hosts own transcript discovery and access; Kilgore or another consumer owns semantic interpretation.

## Linear activity adapters

`LinearActivityConnector` owns configuration, backfill, incremental, and verified webhook ingestion.
A workspace installation registers `LinearGraphqlSource` with `HttpLinearGraphqlTransport` or another
`LinearActivitySource`. Projects, issues, milestones, updates, reports, tasks, and links have separate
accepted-through cursors. Poll and webhook observations share canonical Linear identity. Verified
deliveries are encrypted and replayable; attachment IDs and URLs remain references rather than Aleph
artifact records. Host HTTP, CLI, queue, and scheduler code only creates or dispatches operation
requests and formats the returned result. The host-owned token resolver supplies OAuth or personal
API credentials only when the transport sends a request.

## Email adapters

`EmailConnector` owns bounded backfill and incremental ingestion after a host registers an
`EmailSource`. Gmail, Microsoft Graph, and IMAP adapters normalize provider records to F28 messages
without carrying provider SDK values across the boundary. Sources expose opaque provider checkpoints:
Gmail history IDs, Graph delta links, or IMAP UIDVALIDITY/UID state. Aleph advances a checkpoint only
after the page's messages are accepted by Funes, so a page budget can pause and resume safely.
Original address and header values remain beside normalized addresses. Attachments retain stable
`digory:email-attachment` references for later artifact handling; Aleph stores neither attachment
bytes nor inferred people. Hosts own authentication, provider clients, mailbox authorization, and
credential refresh, and must not include credentials in adapter records.

## Slack credential adapters

`SlackCredentialBroker` gives connector code a resolved access token only through a host-provided
`SlackSecretStore`. Portable Aleph persistence contains the workspace, optional account, opaque
secret reference, source-backed scopes, active/expired/revoked/missing state, and lifecycle
timestamps. Refresh and revocation call the secret store before updating portable metadata. The
Landing migration adapter never accepts token values and leaves the legacy row in place until a host
has verified secure cutover and independently removes it.

## Slack run adapters

`LandingSlackSyncRunAdapter` imports Slack channel-sweep and single-channel audit rows into the shared
ingestion-run lifecycle. Workspace/channel scope, provider run and reconciliation references, and
targets stay in the typed Slack projection. Reconciled continuation cursor, oldest boundary, and
channel high-water state use the shared checkpoint. Slack history remains represented only by stable
Funes references. Landing run persistence remains until a consuming host reconciles cutover.

## Slack activity adapters

`SlackConnector` owns workspace/user/channel/message/file/link polling and verified Events API
ingestion. Registered sources page explicit users, channels, and per-channel history partitions.
Each partition commits a combined cursor/high-water checkpoint after Funes acceptance; page budgets
close as retryable partial work. Polling and events share canonical provider identity.
`AcquireSlackAttachment` resumes chunk acquisition from an opaque checkpoint and hands bytes to a
stable Digory historical reference without creating Aleph-owned content history.

`Connector/Slack/SlackWebApiActivitySource` is the live polling adapter. It resolves the source
installation's opaque credential reference through `SlackCredentialBroker`, then calls
`users.list`, `conversations.list`, or `conversations.history` through
`Connector/Slack/SlackWebApiTransport`. The package includes
`Connector/Slack/HttpSlackWebApiTransport`; the host supplies the Guzzle client and
`SlackSecretStore`.

Register one source for each configured installation:

```php
$broker = new SlackCredentialBroker(
    app(SlackCredentials::class),
    app(SlackSecretStore::class),
    app(ConnectorInstallations::class),
);

app(SlackActivitySources::class)->register(new SlackWebApiActivitySource(
    'slack:workspace/T0123456789',
    $installation->id,
    $broker,
    new HttpSlackWebApiTransport(app(ClientInterface::class)),
));
```

The adapter sends the token only in the Authorization header. Provider responses become plain
`SlackActivity` values before they cross the source boundary. A 429 response records the retry time
on the Aleph attempt and leaves the checkpoint unchanged.

## Provider-neutral communication adapters

`ProviderCommunicationConnector` owns bounded backfill and incremental lifecycle behavior shared by
communication providers. Each registered source supplies provider-specific F28 records and an opaque
checkpoint. Aleph commits that checkpoint only after Funes accepts the page; bounded work closes as a
retryable partial run and resumes from the accepted value. The shared contract retains provider,
source, conversation, message, revision, participant, reply, forward, thread, reaction, attachment,
change, timestamp, reconciliation, and raw-record provenance without resolving people.

`TelegramMessageAdapter` maps direct chats, groups, channels, messages, edits, deletes, forwards,
replies, reactions, media, and unsupported updates. Media uses stable
`digory:telegram-attachment` references. Telegram clients and bot or user tokens remain behind the
registered source boundary and must not be supplied to the adapter.

`SmsMessageAdapter` maps replaceable device-backup and provider records into the same F28 lifecycle.
It retains inbound/outbound direction, original addresses, normalized matching forms, stable hashed
source identities, timestamps, body, source-backed delivery/read state, group participants, and MMS
attachments. It deliberately emits no person identity. MMS media uses stable
`digory:sms-attachment` references; carrier credentials and backup encryption secrets stay behind
the registered source boundary.

`DiscordMessageAdapter` maps gateway and API records while retaining guild, channel, thread,
message, event, author, bot, webhook, reply, reaction, mention, embed, and attachment evidence.
Edits, deletes, and unavailable channels or threads become explicit F28 changes. Gateway sequence or
API cursors use the shared acceptance-gated checkpoint. Attachments use stable
`digory:discord-attachment` references; bot/OAuth credentials and Discord SDK values remain behind
the source boundary.

## Source configuration adapters

`WebCrawlConfigurationAdapter` and `SlackWorkspaceConfigurationAdapter` declare what a source of
their kind is: fields, bounds, and whether a credential is required.
`AbstractSourceConfigurator` applies the shared invariants — unknown inputs and inline credential
values are refused, absent inputs fall back to their environment value then their declared default,
and the accepted declaration is recorded as an observation under the stable `<kind>:<key>` source
reference. `ConfigureSource` resolves the connector, persists the accepted configuration as an
installation, and returns the reference every later run uses. See
[`docs/capabilities/sources-configure.md`](capabilities/sources-configure.md).

## Fakes

Composable doubles live in `Sifrious\Aleph\Testing\Fakes` so orchestration tests need no real
accounts: `MinimalConnector` (no capabilities), `DiscoveryConnector`, `DownloadConnector`,
`IncrementalConnector`, `WebhookConnector`, `HealthyConnector`, `CompositeConnector`, and
`DiscoveryAndDownloadConnector`, and `ConfiguringConnector`. Prefer combining small fakes over one fake with toggles.
