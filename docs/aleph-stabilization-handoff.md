# Aleph stabilization handoff

## Objective

Restore Aleph to a clean, reproducible baseline without losing the in-progress source-configuration work, then reconcile the package with its upstream branch and document its actual capability surface.

## Current state

- Repository: `/Users/mme/gits/sifrious/dep-aleph`
- Branch: `main`
- Upstream relationship at inspection: four commits behind `origin/main`
- Worktree: modified and untracked files; treat every pre-existing change as user-owned work
- Full test result: 345 passed, 107 failed, 1,386 assertions
- Static analysis: passing
- Formatting check: passing
- Whitespace/error check: passing

The worktree contains an in-progress source-configuration capability, including configuration schemas, configuration storage, connector contracts, documentation, and feature tests. Do not discard, overwrite, or casually rebase these changes.

## Primary blocker

The failing tests overwhelmingly share this exception:

```text
Illuminate\Encryption\MissingAppKeyException:
No application encryption key has been specified.
```

`AlephServiceProvider` now resolves Laravel's `Encrypter` while registering `ConnectorInstallations` and `ConnectorCredentials`. `tests/TestCase.php` configures cache and database services but does not set `app.key`. This makes the failures look like a single test-environment regression caused by the new encrypted configuration boundary, rather than 107 independent product regressions.

Confirm that diagnosis by adding a deterministic, test-only application key through the Testbench environment and rerunning the entire suite. Do not weaken or bypass production encryption.

## Upstream divergence

The four upstream commits observed during inspection were:

1. `1b78127` — Add proprietary source license
2. `b76ad2d` — Add A44 publication analytics contract fixtures (#19)
3. `72f9725` — Build YouTube ingestor package API (MME-776) (#18)
4. `7dc05b9` — Add organization CodeRabbit defaults

Reconcile these only after preserving the local source-configuration work. Review the license commit explicitly: the inspected local `composer.json` still declared MIT, so the intended package license must be resolved rather than mechanically accepted.

## Architectural baseline to preserve

- Aleph owns connector discovery, credentials, source accounts/installations, scheduling, health, retry, backfill, and operational ingestion state.
- Funes owns accepted canonical historical records.
- The acceptance boundary between Aleph and Funes should remain explicit.
- Connector manifests, capabilities, contract tests, normalization, and bounded ingestion policies are established package seams.
- Existing coverage includes web crawling, Git/GitHub, Linear, email, Slack, Discord, Telegram, SMS/MMS, AI conversations, and shell history.

The top-level README understates this surface by presenting `web.crawl` as the only admitted capability. Treat that as documentation drift, not as authority to remove implemented connectors.

## Recommended execution order

1. Capture the existing source-configuration changes on a dedicated branch or otherwise create a recoverable checkpoint.
2. Add a deterministic Testbench application key and verify whether it removes the shared failure mode.
3. Run the full test suite and investigate any failures that remain independently.
4. Reconcile the four upstream commits with the preserved local work.
5. Resolve the MIT/proprietary license discrepancy deliberately.
6. Update the README and connector documentation to describe the implemented capability inventory accurately.
7. Run connector-package contract suites where available, not only Aleph's core suite.
8. Pin or tag a compatible Funes revision instead of leaving release readiness dependent on `dev-main` and a local path repository.
9. Produce a clean final diff and release-readiness report before merging.

## Completion evidence

The handoff is complete only when all of the following are recorded:

- The original source-configuration WIP is recoverable and its ownership/history is preserved.
- `composer test` passes in a fresh test environment.
- `composer analyse` passes.
- `vendor/bin/pint --test` passes.
- `git diff --check` passes.
- The upstream commits are reconciled or explicitly deferred with reasons.
- The package license is internally consistent across repository metadata and license files.
- Documentation matches the admitted connector and capability surface.
- The Aleph-to-Funes compatibility target is explicit and reproducible.
- The worktree contains no unexplained changes.

## Non-goals

- Do not redesign the Aleph/Funes ownership boundary during stabilization.
- Do not expand every connector merely because its abstraction exists.
- Do not delete or regenerate the current worktree wholesale.
- Do not declare the package healthy based only on static analysis and formatting while the test suite is red.
