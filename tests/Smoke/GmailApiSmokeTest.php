<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Sifrious\Aleph\Connector\Email\GmailApiSource;
use Sifrious\Aleph\Connector\Email\GmailTokenResolver;
use Sifrious\Aleph\Connector\Email\GmailTokenSecret;
use Sifrious\Aleph\Connector\Email\HttpGmailApiTransport;

it('reads one live Gmail page when credentials are supplied', function (): void {
    $token = getenv('ALEPH_SMOKE_GMAIL_TOKEN');
    $mailbox = getenv('ALEPH_SMOKE_GMAIL_MAILBOX');

    if (! is_string($token) || $token === '' || ! is_string($mailbox) || $mailbox === '') {
        test()->markTestSkipped('Set ALEPH_SMOKE_GMAIL_TOKEN and ALEPH_SMOKE_GMAIL_MAILBOX to run the live Gmail check.');
    }

    $tokens = new class($token) implements GmailTokenResolver
    {
        public function __construct(private readonly string $token) {}

        public function resolve(string $sourceInstallationId): GmailTokenSecret
        {
            return new GmailTokenSecret($this->token);
        }
    };
    $transport = new HttpGmailApiTransport('smoke-installation', $tokens, new Client);
    $source = new GmailApiSource('gmail:smoke', $mailbox, $transport);

    $page = $source->page(null, 1);

    expect($page->messages)->toBeArray()
        ->and($page->checkpoint)->toBeString();
})->group('smoke');
