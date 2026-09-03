<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Sifrious\Aleph\Connector\Slack\HttpSlackWebApiTransport;
use Sifrious\Aleph\Connector\Slack\SlackTokenSecret;

it('authenticates to Slack when the opt-in token is present', function (): void {
    $token = getenv('ALEPH_SMOKE_SLACK_TOKEN');

    if (! is_string($token) || $token === '') {
        $this->markTestSkipped('Set ALEPH_SMOKE_SLACK_TOKEN to run the Slack Web API smoke test.');
    }

    $response = (new HttpSlackWebApiTransport(new Client))->get(
        'auth.test',
        new SlackTokenSecret($token),
        [],
    );

    expect($response['ok'])->toBeTrue()
        ->and($response['team_id'] ?? null)->toBeString();
});
