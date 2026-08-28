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

## Fakes

Composable doubles live in `Sifrious\Aleph\Testing\Fakes` so orchestration tests need no real
accounts: `MinimalConnector` (no capabilities), `DiscoveryConnector`, `DownloadConnector`,
`IncrementalConnector`, `WebhookConnector`, `HealthyConnector`, `CompositeConnector`, and
`DiscoveryAndDownloadConnector`. Prefer combining small fakes over one fake with toggles.
