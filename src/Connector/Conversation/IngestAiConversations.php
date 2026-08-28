<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Conversation;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;

final readonly class IngestAiConversations
{
    public function __construct(
        private ConnectorInstallations $installations,
        private EnvelopeSubmitter $submitter,
    ) {}

    /** @param list<AiConversation> $conversations */
    public function ingest(
        string $sourceReference,
        string $sourceInstallationId,
        array $conversations,
        DateTimeImmutable $capturedAt,
        ?string $attemptId = null,
    ): AiConversationIngestionResult {
        $installation = $this->installations->find($sourceInstallationId);

        if ($installation === null) {
            throw new InvalidArgumentException('AI conversation ingestion requires an existing source installation.');
        }

        $accepted = [];
        $messageCount = 0;

        foreach ($conversations as $conversation) {
            foreach ($conversation->messages as $message) {
                $payload = [
                    'conversation' => [
                        'provider' => $conversation->provider->value,
                        'provider_id' => $conversation->providerId,
                        'provider_metadata' => $conversation->providerMetadata,
                        'raw_reference' => $conversation->rawReference,
                        'source_revision' => $conversation->sourceRevision,
                    ],
                    'message' => [
                        'agent_id' => $message->agentId,
                        'author' => $message->author,
                        'branch_id' => $message->branchId,
                        'ordinal' => $message->ordinal,
                        'parent_provider_id' => $message->parentProviderId,
                        'parts' => array_map(static fn (AiMessagePart $part): array => $part->toArray(), $message->parts),
                        'provider_id' => $message->providerId,
                        'provider_record' => $message->providerRecord,
                        'raw_reference' => $message->rawReference,
                        'role' => $message->role->value,
                        'sidechain' => $message->sidechain,
                        'text' => $message->text(),
                        'thread_id' => $message->threadId,
                    ],
                ];
                $outcome = $this->submitter->submit(new ObservationEnvelope(
                    sourceReference: $sourceReference,
                    sourceName: $conversation->provider->value,
                    resourceReference: implode('/', [
                        $sourceReference,
                        $conversation->provider->value,
                        rawurlencode($conversation->providerId),
                        rawurlencode($message->providerId),
                    ]),
                    observedAt: $capturedAt,
                    payload: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    provenance: new Provenance('ai-conversation', '1.0.0', $installation->id, $capturedAt, details: [
                        'raw_reference' => $message->rawReference ?? $conversation->rawReference,
                    ]),
                    contentType: 'application/json',
                    account: $installation->externalAccountId,
                    stream: $conversation->provider->value.'/'.$conversation->providerId,
                    eventType: 'ai.message',
                    providerId: $message->providerId,
                    providerRevision: $conversation->sourceRevision,
                    extensions: [new ExtensionMetadata('ai.message', 1, [
                        'provider' => $conversation->provider->value,
                        'role' => $message->role->value,
                    ])],
                    occurredAt: $message->occurredAt,
                ), $attemptId);
                $acceptedId = $outcome->acceptedId();

                if (! $outcome->isAuthoritative() || $acceptedId === null) {
                    throw new InvalidArgumentException('Funes did not accept the AI conversation message.');
                }

                $accepted[] = $acceptedId;
                $messageCount++;
            }
        }

        return new AiConversationIngestionResult(
            count($conversations),
            $messageCount,
            array_values(array_unique($accepted)),
        );
    }
}
