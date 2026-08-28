# Domain reconciliation

`DomainReconciliations` records an explicit decision for each observed domain using the existing
source-scope association store. The domain is a namespaced `domain` reference and its identifier is
also the source stream. Candidate relationships are namespaced `project`, `site`, or `repository`
references. Display names and local database IDs are rejected by the underlying entity-reference
contract.

Every decision supplies a state, actor, and time. Unassigned decisions contain no candidates;
ambiguous decisions retain at least two. Confirmed relationships may be many-to-many. Rejected and
superseded relationships remain visible, and replacing a candidate supersedes its prior row instead
of deleting it. Replaying identical input returns the same association identity and timestamps.

`groupedByState` returns a presentation-neutral map for a local host. Candidate selection,
authorization, display, and canonical-reference governance stay with the host. The package infers
nothing from a domain name and exposes no DNS, registrar, Linear, or project-management mutation.
