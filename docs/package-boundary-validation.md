# Aleph package boundary validation

Validated for MME-1174 on 2026-08-28.

| Criterion | Package evidence | Result |
| --- | --- | --- |
| Connector discovery does not move into Funes | Connector capability contracts live under `Sifrious\Aleph\Connector`; dependency tests inspect Funes for Aleph and discovery references. | Passed |
| Observations carry sufficient identity and provenance | The end-to-end boundary fixture retains account, stream, event, provider ID, connector/version, installation, capture time, and run in Funes metadata. | Passed |
| Retry is deterministic and duplicate-safe | Replaying the same connector-derived envelope returns the same Funes ID and leaves one observation. Retry, resume, and checkpoint suites cover operational reconstruction. | Passed |
| Collection is read-only by default | The public capability catalogue contains discovery, retrieval, observation, normalization, and health capabilities; it exposes no generic provider write, update, or delete operation. | Passed |
| Failures, retries, and health remain observable | Durable run attempts, failure timelines, retry lineage, checkpoint continuations, freshness, and expiring health evidence are package read models. Health changes do not write Funes history. | Passed |
| Provider credentials and schemas remain bounded | Aleph depends on no provider SDK. Connector configuration marks secret fields, source accounts use opaque credential references, and Aleph-managed material is encrypted outside Funes. | Passed |
| A connector reaches Funes end to end | The independent `DiscoveryAndDownloadConnector` is registered, discovers a source, creates an observation envelope, crosses the acceptance gateway, and is read back with provenance. | Passed |

The validation uses package fakes and an in-memory SQLite database. It proves the reusable boundary,
not the behavior of a particular live provider. Real-provider authentication remains connector-level
verification where an individual connector ticket requires it.
