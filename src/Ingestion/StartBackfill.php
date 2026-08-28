<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\Capability as ConnectorCapability;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;

final readonly class StartBackfill
{
    public function __construct(
        private ConnectorInstallations $installations,
        private ConnectorRegistry $connectors,
        private IngestionRuns $runs,
        private IngestionPartitions $partitions,
        private QueueIngestion $queue,
    ) {}

    public function start(BackfillRequest $request): BackfillResult
    {
        $installation = $this->installations->find($request->sourceInstallationId);

        if ($installation === null || ! $installation->enabled) {
            throw new InvalidArgumentException('A backfill requires an enabled source installation.');
        }

        if (! $this->connectors->has($installation->connectorId)
            || ! $this->connectors->manifest($installation->connectorId)->supports(ConnectorCapability::Backfills)
        ) {
            throw new InvalidArgumentException('The connector does not advertise historical backfill.');
        }

        if (! $request->authorization->granted
            || trim($request->authorization->actorReference) === ''
            || trim($request->authorization->decisionReference) === ''
        ) {
            throw new InvalidArgumentException('The backfill is not authorized.');
        }

        if (trim($request->sourceReference) === ''
            || trim($request->scope) === ''
            || trim($request->normalizerVersion) === ''
            || trim($request->idempotencyKey) === ''
        ) {
            throw new InvalidArgumentException('Backfill identity, scope, normalizer version, and idempotency key are required.');
        }

        $partitionKeys = array_values(array_unique(array_filter(
            array_map(trim(...), $request->partitions),
            static fn (string $partition): bool => $partition !== '',
        )));
        sort($partitionKeys, SORT_STRING);

        if ($partitionKeys === [] || count($partitionKeys) > $request->budget->maxPartitions) {
            throw new InvalidArgumentException('Backfill partitions must fit the declared work budget.');
        }

        $key = implode(':', ['backfill', $request->sourceInstallationId, $request->idempotencyKey]);
        $existing = $this->runs->findByIdempotencyKey($key);
        $run = $existing ?? $this->runs->request(
            sourceReference: $request->sourceReference,
            capability: Capability::Backfill,
            parameters: [
                'range_format' => $request->range->format,
                'from' => $request->range->from,
                'to' => $request->range->to,
                'scope' => $request->scope,
                'partitions' => $partitionKeys,
                'force' => $request->force,
                'normalizer_version' => $request->normalizerVersion,
                'rate_limit' => $request->rateLimit->toArray(),
                'budget' => $request->budget->toArray(),
            ],
            connectorId: $installation->connectorId,
            sourceInstallationId: $installation->id,
            idempotencyKey: $key,
            trigger: IngestionTrigger::Manual,
            requestedBy: $request->authorization->actorReference,
            authorizationDecision: $request->authorization->decisionReference,
        );
        $partitions = $this->partitions->create($run, $partitionKeys, new DateTimeImmutable);
        $attempt = $this->queue->dispatch($run);

        return new BackfillResult($run, $attempt, $partitions, $existing !== null);
    }
}
