<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Communication;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class F28CommunicationRecord
{
    /**
     * @param  list<CommunicationParticipant>  $participants
     * @param  list<CommunicationReaction>  $reactions
     * @param  list<CommunicationAttachment>  $attachments
     * @param  array<string, mixed>  $reconciliation
     */
    public function __construct(
        public CommunicationProvider $provider,
        public string $sourceReference,
        public string $conversationId,
        public string $conversationKind,
        public string $providerId,
        public string $providerRevision,
        public CommunicationChange $change,
        public DateTimeImmutable $occurredAt,
        public array $participants,
        public ?string $body,
        public ?string $replyTo,
        public ?string $forwardedFrom,
        public ?string $threadId,
        public array $reactions,
        public array $attachments,
        public array $reconciliation,
        public string $rawReference,
        public ?DateTimeImmutable $editedAt = null,
        public ?DateTimeImmutable $deletedAt = null,
    ) {
        if (trim($sourceReference) === '' || trim($conversationId) === '' || trim($conversationKind) === ''
            || trim($providerId) === '' || trim($providerRevision) === '' || trim($rawReference) === '') {
            throw new InvalidArgumentException('F28 communication records require source, conversation, provider, revision, and raw identities.');
        }
    }

    public function resourceReference(): string
    {
        return $this->sourceReference.'/'.$this->conversationId.'/'.$this->providerId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract' => 'F28',
            'provider' => $this->provider->value,
            'source_reference' => $this->sourceReference,
            'conversation_id' => $this->conversationId,
            'conversation_kind' => $this->conversationKind,
            'provider_id' => $this->providerId,
            'provider_revision' => $this->providerRevision,
            'change' => $this->change->value,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'edited_at' => $this->editedAt?->format(DATE_ATOM),
            'deleted_at' => $this->deletedAt?->format(DATE_ATOM),
            'participants' => array_map(static fn (CommunicationParticipant $participant): array => $participant->toArray(), $this->participants),
            'body' => $this->body,
            'reply_to' => $this->replyTo,
            'forwarded_from' => $this->forwardedFrom,
            'thread_id' => $this->threadId,
            'reactions' => array_map(static fn (CommunicationReaction $reaction): array => $reaction->toArray(), $this->reactions),
            'attachments' => array_map(static fn (CommunicationAttachment $attachment): array => $attachment->toArray(), $this->attachments),
            'reconciliation' => $this->reconciliation,
            'raw_reference' => $this->rawReference,
        ];
    }
}
