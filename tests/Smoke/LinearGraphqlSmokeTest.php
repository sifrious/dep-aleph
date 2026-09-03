<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Sifrious\Aleph\Connector\Linear\HttpLinearGraphqlTransport;
use Sifrious\Aleph\Connector\Linear\LinearGraphqlSource;
use Sifrious\Aleph\Connector\Linear\LinearStream;
use Sifrious\Aleph\Connector\Linear\LinearTokenResolver;
use Sifrious\Aleph\Connector\Linear\LinearTokenSecret;

it('reads one live Linear GraphQL page when credentials are supplied', function (): void {
    $token = getenv('ALEPH_SMOKE_LINEAR_TOKEN');
    $workspace = getenv('ALEPH_SMOKE_LINEAR_WORKSPACE');

    if (! is_string($token) || $token === '' || ! is_string($workspace) || $workspace === '') {
        test()->markTestSkipped('Set ALEPH_SMOKE_LINEAR_TOKEN and ALEPH_SMOKE_LINEAR_WORKSPACE to run the live Linear check.');
    }

    $tokens = new class($token) implements LinearTokenResolver
    {
        public function __construct(private readonly string $token) {}

        public function resolve(string $sourceInstallationId): LinearTokenSecret
        {
            return new LinearTokenSecret($this->token, oauth: getenv('ALEPH_SMOKE_LINEAR_OAUTH') === '1');
        }
    };
    $transport = new HttpLinearGraphqlTransport('smoke-installation', $tokens, new Client);
    $source = new LinearGraphqlSource('linear:'.$workspace, $transport);

    $page = $source->page(LinearStream::Issues, null, 1);

    expect($page->activities)->toBeArray();
})->group('smoke');
