<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\AppleMail;

use InvalidArgumentException;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\Contracts\DownloadsArtifacts;
use Sifrious\Aleph\Connector\Values\Artifact;
use Sifrious\Aleph\Connector\Values\ArtifactRequest;

/**
 * Decoupled Apple Mail.app local-mailbox adapter. Accepts already-read local
 * mailbox message payloads (NativePHP / desktop hosts may supply them after Full
 * Disk / Mail prompts). Domain core does not import NativePHP APIs.
 */
final class AppleMailConnector implements Connector, DownloadsArtifacts
{
    public function id(): string
    {
        return 'apple-mail';
    }

    public function name(): string
    {
        return 'Apple Mail';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function configuration(): ConfigurationSchema
    {
        return new ConfigurationSchema;
    }

    public function downloadArtifact(ArtifactRequest $request): Artifact
    {
        $input = is_string($request->parameters['input'] ?? null) ? $request->parameters['input'] : null;

        if ($input !== 'local_mailbox_message') {
            throw new InvalidArgumentException('Apple Mail ingestion requires input mode [local_mailbox_message].');
        }

        $raw = $request->parameters['message'] ?? null;

        if (! is_array($raw)) {
            throw new InvalidArgumentException('Apple Mail local mailbox download requires a message payload.');
        }

        $message = LocalAppleMailMessage::fromArray($raw);
        $encoded = json_encode($message->toArray(), JSON_THROW_ON_ERROR);

        return new Artifact(
            reference: $request->artifactReference,
            mediaType: 'application/json',
            contents: $encoded,
            metadata: [
                'source_reference' => $request->sourceReference,
                'input' => 'local_mailbox_message',
                'source' => 'mail.app.local_mailbox',
                'rfc_message_id' => $message->normalizedMessageId(),
                'attachment_count' => count($message->attachments),
                'mailbox_path' => $message->mailboxPath,
            ],
        );
    }
}
