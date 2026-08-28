<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\Capability as ConnectorCapability;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;

final readonly class StartIncrementalSync
{
    public function __construct(
        private ConnectorInstallations $installations,
        private ConnectorRegistry $connectors,
        private SourceStreams $streams,
        private IngestionCheckpoints $checkpoints,
        private IngestionRuns $runs,
        private QueueIngestion $queue,
    ) {}

    public function start(IncrementalSyncRequest $request): IncrementalSyncResult
    {
        $stream = $this->streams->find($request->sourceStreamId);
        $installation = $stream === null ? null : $this->installations->find($stream->sourceInstallationId);

        if ($stream === null || ! $stream->enabled || $installation === null || ! $installation->enabled) {
            throw new InvalidArgumentException('Incremental sync requires an enabled stream and source installation.');
        }

        if (! $this->connectors->has($installation->connectorId)
            || ! $this->connectors->manifest($installation->connectorId)->supports(ConnectorCapability::SyncsIncrementally)
        ) {
            throw new InvalidArgumentException('The connector does not advertise incremental synchronization.');
        }

        if (! $request->authorization->granted
            || trim($request->authorization->actorReference) === ''
            || trim($request->authorization->decisionReference) === ''
        ) {
            throw new InvalidArgumentException('Incremental synchronization is not authorized.');
        }

        if (trim($request->sourceReference) === '' || trim($request->partitionKey) === '' || trim($request->idempotencyKey) === '') {
            throw new InvalidArgumentException('Incremental source, partition, and idempotency identities are required.');
        }

        $checkpoint = $this->checkpoints->latest($stream, Capability::IncrementalSync, $request->partitionKey);
        $key = implode(':', ['incremental', $stream->id, $request->partitionKey, $request->idempotencyKey]);
        $existing = $this->runs->findByIdempotencyKey($key);
        $run = $existing ?? $this->runs->request(
            sourceReference: $request->sourceReference,
            capability: Capability::IncrementalSync,
            parameters: [
                'strategy' => $stream->syncStrategy->value,
                'partition' => $request->partitionKey,
                'checkpoint' => $checkpoint === null ? null : [
                    'id' => $checkpoint->id,
                    'version' => $checkpoint->version,
                    'format' => $checkpoint->value->format,
                    'serializer_version' => $checkpoint->value->serializerVersion,
                    'value' => $checkpoint->value->value,
                ],
                'full_reconciliation' => $request->fullReconciliation,
                'budget' => $request->budget->toArray(),
            ],
            connectorId: $installation->connectorId,
            sourceInstallationId: $installation->id,
            idempotencyKey: $key,
            trigger: IngestionTrigger::Manual,
            requestedBy: $request->authorization->actorReference,
            authorizationDecision: $request->authorization->decisionReference,
        );
        $attempt = $this->queue->dispatch($run);

        return new IncrementalSyncResult($stream, $run, $attempt, $checkpoint, $existing !== null);
    }
}
