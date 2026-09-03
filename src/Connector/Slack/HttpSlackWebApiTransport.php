<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Slack;

use DateTimeImmutable;
use GuzzleHttp\ClientInterface;
use RuntimeException;

final readonly class HttpSlackWebApiTransport implements SlackWebApiTransport
{
    public function __construct(private ClientInterface $http) {}

    public function get(string $method, SlackTokenSecret $token, array $query): array
    {
        $response = $this->http->request('GET', 'https://slack.com/api/'.$method, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token->reveal(),
            ],
            'query' => $query,
            'http_errors' => false,
            'timeout' => 30,
        ]);

        if ($response->getStatusCode() === 429) {
            $seconds = max(1, (int) $response->getHeaderLine('Retry-After'));

            throw new SlackRateLimited((new DateTimeImmutable)->modify("+{$seconds} seconds"));
        }

        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ($payload['ok'] ?? false) !== true) {
            $error = is_array($payload) && is_string($payload['error'] ?? null)
                ? $payload['error']
                : 'invalid_response';

            throw new RuntimeException("Slack API method [{$method}] failed with [{$error}].");
        }

        return $payload;
    }
}
