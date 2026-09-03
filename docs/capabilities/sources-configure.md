# `sources.configure`

Declaring what a source is and what bounds it, before anything is fetched.

A connector implements `Sifrious\Aleph\Connector\Contracts\ConfiguresSources`. The shared
invariants live in `Configuration\AbstractSourceConfigurator`; a subclass supplies only the
`SourceConfigurationProvider` for its source kind.

```php
$configured = app(ConfigureSource::class)->configure('web-crawl', new SourceConfigurationRequest(
    sourceKey: 'ahsd',
    name: 'Abington Heights School District',
    values: ['seeds' => ['https://www.ahsd.org/'], 'allowed_hosts' => ['ahsd.org', '*.ahsd.org']],
));

$configured->sourceReference(); // web:ahsd
```

## What the base class guarantees

| Invariant | Behavior |
| --- | --- |
| Source key | must be a lowercase slug; the source reference is `<kind>:<key>` and never changes |
| Unknown input | rejected — `unknown_input` |
| Inline credential value | rejected — `inline_secret`; a secret field can never be submitted as a value |
| Absent input | environment value, then declared default; a required input with neither is rejected — `missing_input` |
| Credential requirement | a provider declaring a `CredentialKind` requires an opaque reference — `missing_credential`; one declaring none refuses a reference — `unexpected_credential` |
| Bounds | delegated to the provider — `out_of_bounds` |
| Record | the accepted declaration is recorded as an observation under the source reference |

Credentials reach Aleph only as an opaque reference such as `vault://slack/acme`. A secret
`ConfigurationField` may declare neither a default nor an environment key, so no credential value
can enter configuration history — `ConnectorContract::configurationViolations()` enforces this.

## Web crawl inputs

Adapter: `Configuration\WebCrawlConfigurationAdapter`. Source kind `web`. No credential.

| Input | Env key | Type | Default | Secret | When absent |
| --- | --- | --- | --- | --- | --- |
| `seeds` | `ALEPH_WEB_SEEDS` | array | — | no | rejected |
| `allowed_hosts` | `ALEPH_WEB_ALLOWED_HOSTS` | array | — | no | rejected |
| `excluded` | `ALEPH_WEB_EXCLUDED` | array | `[]` | no | default applied |
| `query_parameters` | `ALEPH_WEB_QUERY_PARAMETERS` | array | `[]` | no | default applied |
| `calendar_signals` | `ALEPH_WEB_CALENDAR_SIGNALS` | array | `[]` | no | default applied |
| `max_pages` | `ALEPH_WEB_MAX_PAGES` | integer | `100` | no | default applied |
| `max_depth` | `ALEPH_WEB_MAX_DEPTH` | integer | `2` | no | default applied |

Array inputs read from the environment are comma-separated. The adapter validates the bound set
through `Web\WebSource`, so a crawl configured here obeys the same host, exclusion, and limit rules
as one declared in `aleph.web_sources`. The accepted record carries `limits` as
`{max_pages, max_depth}`.

## Slack workspace inputs

Adapter: `Configuration\SlackWorkspaceConfigurationAdapter`. Source kind `slack`. Requires a
`CredentialKind::Token` reference.

| Input | Env key | Type | Default | Secret | When absent |
| --- | --- | --- | --- | --- | --- |
| `workspace` | `ALEPH_SLACK_WORKSPACE` | string | — | no | rejected |
| `channels` | `ALEPH_SLACK_CHANNELS` | array | `[]` | no | default applied — every channel the credential can read |
| `history_days` | `ALEPH_SLACK_HISTORY_DAYS` | integer | `30` | no | default applied |

The workspace must be a Slack workspace identifier (`T…`) and every channel a channel identifier
(`C…`, `G…`, or `D…`). The token itself is never an input; it stays wherever the host keeps
credentials and reaches Aleph as `credentialReference`.

## Adding a source kind

Implement `SourceConfigurationProvider` — `sourceKind()`, `schema()`, `credentialKind()`,
`bound()` — and extend `AbstractSourceConfigurator` with a `provider()` returning it. The
connector implements `ConfiguresSources` by delegating to that configurator and returns the
adapter's schema from `configuration()`, so the manifest and the validation cannot disagree.
