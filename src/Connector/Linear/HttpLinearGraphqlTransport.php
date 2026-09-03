<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Linear;

use GuzzleHttp\ClientInterface;
use RuntimeException;

final readonly class HttpLinearGraphqlTransport implements LinearGraphqlTransport
{
    public function __construct(
        private string $sourceInstallationId,
        private LinearTokenResolver $tokens,
        private ClientInterface $http,
    ) {}

    public function query(string $document, array $variables): array
    {
        $response = $this->http->request('POST', 'https://api.linear.app/graphql', [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => $this->tokens->resolve($this->sourceInstallationId)->authorization(),
                'Content-Type' => 'application/json',
            ],
            'json' => ['query' => $document, 'variables' => $variables],
            'http_errors' => false,
            'timeout' => 30,
        ]);
        $status = $response->getStatusCode();
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if ($status < 200 || $status >= 300 || ! is_array($payload)) {
            throw new RuntimeException("Linear GraphQL request failed with HTTP status [{$status}].");
        }

        if (is_array($payload['errors'] ?? null) && $payload['errors'] !== []) {
            $message = $payload['errors'][0]['message'] ?? 'unknown GraphQL error';
            throw new RuntimeException('Linear GraphQL request failed: '.(is_string($message) ? $message : 'unknown GraphQL error').'.');
        }

        return $payload;
    }
}
