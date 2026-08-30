"""Local video ingest twin (MME-771 / MME-2206).

Sibling of the PHP video-file adapter. Emits the same Funes observation envelope
shape (MME-1223). Does not import PHP, does not reimplement Funes acceptance,
and is not a Composer dependency.
"""

from __future__ import annotations

import base64
import hashlib
import json
import sys
from typing import Any


CAPABILITY = "video-file"
SOURCE_NAME = "local-video-file"
CONNECTOR_VERSION = "1.0.0"
EXTENSION_NAMESPACE = "video.file"
LANGUAGE = "python"


def build_envelope(
    *,
    source_reference: str,
    source_installation_id: str,
    run_id: str,
    artifact_reference: str,
    media_type: str,
    contents: bytes,
    metadata: dict[str, Any] | None = None,
) -> dict[str, Any]:
    metadata = metadata or {}
    checksum = hashlib.sha256(contents).hexdigest()
    byte_count = len(contents)
    artifact = {
        "reference": f"{artifact_reference}#media",
        "relationship": "primary",
        "media_type": media_type,
        "metadata": {
            "bytes": byte_count,
            "sha256": checksum,
        },
    }
    extension = {
        "namespace": EXTENSION_NAMESPACE,
        "version": 1,
        "data": {
            "artifact_reference": artifact_reference,
            "metadata": metadata,
            "checksum": {
                "algorithm": "sha256",
                "value": checksum,
                "bytes": byte_count,
            },
        },
    }
    provenance = {
        "connector": CAPABILITY,
        "connector_version": CONNECTOR_VERSION,
        "installation": source_installation_id,
        "run": run_id,
        "details": {
            "artifact_reference": artifact_reference,
            "language": LANGUAGE,
        },
    }

    return {
        "source_reference": source_reference,
        "source_name": SOURCE_NAME,
        "resource_reference": artifact_reference,
        "content_type": media_type,
        "payload_sha256": checksum,
        "payload_base64": base64.b64encode(contents).decode("ascii"),
        "payload_bytes": byte_count,
        "provenance": provenance,
        "artifacts": [artifact],
        "extensions": [extension],
        "aleph": {
            "envelope_version": 1,
            "artifacts": [artifact],
            "provenance": provenance,
        },
    }


def comparable_shape(document: dict[str, Any]) -> dict[str, Any]:
    provenance = document.get("provenance") or {}
    details = dict(provenance.get("details") or {})
    details.pop("language", None)
    aleph = document.get("aleph") or {}

    return {
        "source_name": document.get("source_name"),
        "content_type": document.get("content_type"),
        "payload_sha256": document.get("payload_sha256"),
        "payload_bytes": document.get("payload_bytes"),
        "provenance": {
            "connector": provenance.get("connector"),
            "connector_version": provenance.get("connector_version"),
            "details": {
                "artifact_reference": details.get("artifact_reference"),
            },
        },
        "artifacts": document.get("artifacts"),
        "extensions": document.get("extensions"),
        "aleph": {
            "envelope_version": aleph.get("envelope_version"),
        },
    }


def main() -> int:
    request = json.load(sys.stdin)
    encoded = request.get("contents_base64") or ""
    contents = base64.b64decode(encoded, validate=True)
    document = build_envelope(
        source_reference=str(request["source_reference"]),
        source_installation_id=str(request["source_installation_id"]),
        run_id=str(request["run_id"]),
        artifact_reference=str(request["artifact_reference"]),
        media_type=str(request["media_type"]),
        contents=contents,
        metadata=dict(request.get("metadata") or {}),
    )
    json.dump(document, sys.stdout, separators=(",", ":"), sort_keys=False)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
