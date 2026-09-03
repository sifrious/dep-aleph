<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Sifrious\Aleph\Connector\Linear\HttpLinearGraphqlTransport;
use Sifrious\Aleph\Connector\Linear\LinearTokenResolver;
use Sifrious\Aleph\Connector\Linear\LinearTokenSecret;

it('queries Linear with a token resolved for the source installation', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"data":{"viewer":{"id":"user-1"}}}')]));
    $stack->push(Middleware::history($history));
    $tokens = new class implements LinearTokenResolver
    {
        public string $installation = '';

        public function resolve(string $sourceInstallationId): LinearTokenSecret
        {
            $this->installation = $sourceInstallationId;

            return new LinearTokenSecret('oauth-token');
        }
    };
    $transport = new HttpLinearGraphqlTransport('installation-1', $tokens, new Client(['handler' => $stack]));

    $result = $transport->query('query Me { viewer { id } }', ['first' => 1]);

    expect($result['data']['viewer']['id'])->toBe('user-1')
        ->and($tokens->installation)->toBe('installation-1')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer oauth-token');
});
