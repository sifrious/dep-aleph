<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use GuzzleHttp\ClientInterface;
use RuntimeException;

final readonly class HttpGmailApiTransport implements GmailApiTransport
{
    public function __construct(
        private string $sourceInstallationId,
        private GmailTokenResolver $tokens,
        private ClientInterface $http,
    ) {}

    public function get(string $path, array $query = []): array
    {
        $response = $this->http->request('GET', 'https://gmail.googleapis.com/gmail/v1/'.ltrim($path, '/'), [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->tokens->resolve($this->sourceInstallationId)->reveal(),
            ],
            'query' => $query,
            'http_errors' => false,
            'timeout' => 30,
        ]);
        $status = $response->getStatusCode();
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if ($status === 404 && is_string($query['startHistoryId'] ?? null)) {
            throw GmailHistoryExpired::forHistoryId($query['startHistoryId']);
        }

        if ($status < 200 || $status >= 300 || ! is_array($payload)) {
            throw new RuntimeException("Gmail API request failed with HTTP status [{$status}].");
        }

        return $payload;
    }
}
