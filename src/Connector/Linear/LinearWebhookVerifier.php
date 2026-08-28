<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

final readonly class LinearWebhookVerifier
{
    public function verify(string $payload, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);
        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;

        return hash_equals($expected, $provided);
    }
}
