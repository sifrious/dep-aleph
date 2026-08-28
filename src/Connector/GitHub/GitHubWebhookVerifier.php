<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

final readonly class GitHubWebhookVerifier
{
    public function verify(string $payload, string $signature, string $secret): bool
    {
        if ($secret === '' || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signature);
    }
}
