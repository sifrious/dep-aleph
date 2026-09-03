# Connector support

Contract support means the package has a typed connector boundary. Fixture verification means the
normal test suite runs the lifecycle with recorded or local input. Live verification means an
external provider or binary has a separate opt-in check. A blank live column is deliberate.

| Connector | Contract support | Fixture verification | Live verification | Host requirement |
| --- | --- | --- | --- | --- |
| Web crawl | configuration and crawl | full bounded crawl | HTTP integration tests | network access |
| Slack | configuration, backfill, incremental, webhook | recorded Web API and webhook fixtures | opt-in `auth.test` | secret store and Slack token |
| GitHub | backfill, incremental, webhook | yes |  | provider transport and webhook secret |
| Linear | backfill, incremental, webhook | yes |  | GraphQL transport and webhook secret |
| Email | backfill and incremental | Gmail, Graph, and IMAP fixtures |  | provider source |
| Git | backfill and incremental | local repository fixtures |  | repository reader |
| Shell history | backfill and incremental | zsh, Atuin, and Claude fixtures |  | local history reader |
| AI conversations | backfill and incremental | Claude, Codex, and alternate fixtures |  | transcript reader |
| Communication records | backfill and incremental | Telegram, Discord, SMS, and MMS fixtures |  | provider source |
| Google Drive | artifact download and document handoff | DOCX, PDF, text, and deferred formats |  | Drive client |
| YouTube | artifact download | process fixtures | opt-in `yt-dlp` check | `yt-dlp` |
| Podcast | artifact download | RSS and HTTP fixtures |  | network access |
| Apple Mail | artifact download | local message fixtures |  | mailbox reader |
| Image | artifact download and conversion | yes |  | GD for conversion |
| Handwriting | artifact download and optional derivation | yes |  | optional host OCR model |
| MIDI | artifact download and parsing | yes |  | none |
| Score and tab | artifact download and optional derivation | yes |  | optional host model |
| Spoken sound | artifact download | yes |  | audio source |
| Local video | artifact download | PHP and Python fixture parity |  | optional Python adapter |
| NativePHP desktop | artifact download | yes |  | desktop submission host |

Normal CI never needs provider credentials. Run external checks explicitly:

```bash
ALEPH_SMOKE_EXTERNAL_TOOLS=1 vendor/bin/pest tests/Smoke/ExternalToolSmokeTest.php
ALEPH_SMOKE_SLACK_TOKEN='resolved-by-your-shell' vendor/bin/pest tests/Smoke/SlackWebApiSmokeTest.php
```

The Google Drive formatter runs in normal CI because DOCX uses PHP's DOM and Zip extensions and PDF
uses a Composer dependency. XLSX and PPTX remain accepted original artifacts with an explicit
deferred format result.
