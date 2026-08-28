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
| `sources.discover` | `DiscoversSources` | `discoverSources` | yes |
| `history.backfill` | `Backfills` | `backfill` | yes |
| `sync.incremental` | `SyncsIncrementally` | `syncIncrementally` | yes |
| `webhooks.consume` | `ConsumesWebhooks` | `consumeWebhook` | yes |
| `artifacts.download` | `DownloadsArtifacts` | `downloadArtifact` | yes |
| `health.check` | `ChecksHealth` | `checkHealth` | yes |
| `content.extract` | `ExtractsContent` | `extractContent` | no |
| `records.normalize` | `Normalizes` | `normalize` | no |
| `agents.assist` | `UsesAgents` | `runAgentTask` | no |

The last three participate in a run rather than being started on their own — see D-030.

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

`GitHubActivityConnector` owns backfill, incremental, and webhook behavior. A consuming application
registers an account-specific `GitHubActivitySource` that translates REST or GraphQL responses into
canonical activities and registers its resolved webhook secret by source installation. The package
owns cursor advancement, Funes submission, signature validation, encrypted delivery replay, account
isolation, and rate-limit failure evidence. OAuth callbacks, token acquisition, provider clients, and
HTTP route handling stay in the consuming application.

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

`LinearActivityConnector` owns backfill, incremental, and verified webhook ingestion. A workspace
installation registers either `LinearGraphqlSource` with a credential-resolving transport or another
`LinearActivitySource`. Projects, issues, milestones, updates, reports, tasks, and links have separate
accepted-through cursors. Poll and webhook observations share canonical Linear identity. Verified
deliveries are encrypted and replayable; attachment IDs and URLs remain references rather than Aleph
artifact records. Host HTTP, CLI, queue, and scheduler code only creates or dispatches operation
requests and formats the returned result.

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

## Fakes

Composable doubles live in `Sifrious\Aleph\Testing\Fakes` so orchestration tests need no real
accounts: `MinimalConnector` (no capabilities), `DiscoveryConnector`, `DownloadConnector`,
`IncrementalConnector`, `WebhookConnector`, `HealthyConnector`, `CompositeConnector`, and
`DiscoveryAndDownloadConnector`. Prefer combining small fakes over one fake with toggles.
