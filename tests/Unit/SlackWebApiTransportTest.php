<?php

declare(strict_types=1);

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Sifrious\Aleph\Connector\Slack\HttpSlackWebApiTransport;
use Sifrious\Aleph\Connector\Slack\SlackRateLimited;
use Sifrious\Aleph\Connector\Slack\SlackTokenSecret;

it('sends a Slack token in the authorization header and returns decoded data', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['ok' => true, 'members' => []], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));
    $transport = new HttpSlackWebApiTransport(new Client(['handler' => $stack]));

    $response = $transport->get('users.list', new SlackTokenSecret('xoxb-fixture'), ['limit' => 25]);
    $request = $history[0]['request'] ?? null;

    expect($response['members'])->toBe([])
        ->and($request)->toBeInstanceOf(RequestInterface::class)
        ->and($request?->getHeaderLine('Authorization'))->toBe('Bearer xoxb-fixture')
        ->and((string) $request?->getUri())->toContain('/api/users.list', 'limit=25');
});

it('turns Slack 429 responses into an observable retry time', function (): void {
    $transport = new HttpSlackWebApiTransport(new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new Response(429, ['Retry-After' => '60'], '{}'),
        ])),
    ]));
    $before = new DateTimeImmutable('+59 seconds');

    try {
        $transport->get('conversations.history', new SlackTokenSecret('xoxb-fixture'), []);
        $failure = null;
    } catch (SlackRateLimited $caught) {
        $failure = $caught;
    }

    expect($failure)->toBeInstanceOf(SlackRateLimited::class)
        ->and($failure?->retryAt)->toBeGreaterThan($before);
});
