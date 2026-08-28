<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use JsonException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;

final readonly class LaunchIngestion
{
    public function __construct(
        private ConnectorRegistry $connectors,
        private ConnectorInstallations $installations,
        private IngestionRuns $runs,
        private ManualIngestionDispatcher $dispatcher,
    ) {}

    public function launch(LaunchIngestionRequest $request): LaunchIngestionResult
    {
        $this->validateRequest($request);
        $this->validateAuthorization($request->authorization);
        $installation = $this->installations->find($request->sourceInstallationId);

        if ($installation === null) {
            throw new LaunchRejected('installation_not_found', 'The source installation does not exist.');
        }

        if (! $installation->enabled) {
            throw new LaunchRejected('installation_disabled', 'The source installation is disabled.');
        }

        if (! $this->connectors->has($installation->connectorId)) {
            throw new LaunchRejected('connector_not_registered', 'The source connector is not registered.');
        }

        $manifest = $this->connectors->manifest($installation->connectorId);

        if (! $request->capability->isDispatchable() || ! $manifest->supports($request->capability)) {
            throw new LaunchRejected('capability_not_supported', 'The connector does not advertise this manual operation.');
        }

        $this->validateParameters($request->parameters);
        $key = implode(':', [
            'manual',
            $request->sourceInstallationId,
            $request->capability->value,
            $request->idempotencyKey,
        ]);
        $existing = $this->runs->findByIdempotencyKey($key);
        $run = $existing ?? $this->runs->request(
            sourceReference: $request->sourceReference,
            capability: Capability::from($request->capability->value),
            parameters: $request->parameters,
            connectorId: $installation->connectorId,
            sourceInstallationId: $installation->id,
            idempotencyKey: $key,
            trigger: IngestionTrigger::Manual,
            requestedBy: $request->authorization->actorReference,
            authorizationDecision: $request->authorization->decisionReference,
        );
        $result = new LaunchIngestionResult($run, $existing !== null);

        if ($existing === null) {
            $this->dispatcher->dispatch($result);
        }

        return $result;
    }

    private function validateRequest(LaunchIngestionRequest $request): void
    {
        if (trim($request->sourceReference) === '') {
            throw new LaunchRejected('source_reference_invalid', 'Manual ingestion requires a stable source reference.');
        }

        if (trim($request->idempotencyKey) === '') {
            throw new LaunchRejected('idempotency_key_invalid', 'Manual ingestion requires an idempotency key.');
        }
    }

    private function validateAuthorization(LaunchAuthorization $authorization): void
    {
        if (trim($authorization->actorReference) === '' || trim($authorization->decisionReference) === '') {
            throw new LaunchRejected('authorization_context_invalid', 'Authorization requires stable actor and decision references.');
        }

        if (! $authorization->granted) {
            throw new LaunchRejected('authorization_denied', $authorization->reason ?? 'Manual ingestion was not authorized.');
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function validateParameters(array $parameters): void
    {
        try {
            json_encode($parameters, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LaunchRejected('parameters_invalid', 'Manual ingestion parameters must be JSON serializable.');
        }
    }
}
