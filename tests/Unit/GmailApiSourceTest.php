<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Sifrious\Aleph\Connector\Email\EmailChangeKind;
use Sifrious\Aleph\Connector\Email\GmailApiSource;
use Sifrious\Aleph\Connector\Email\GmailApiTransport;
use Sifrious\Aleph\Connector\Email\GmailCheckpoint;
use Sifrious\Aleph\Connector\Email\GmailHistoryExpired;
use Sifrious\Aleph\Connector\Email\GmailTokenResolver;
use Sifrious\Aleph\Connector\Email\GmailTokenSecret;
use Sifrious\Aleph\Connector\Email\HttpGmailApiTransport;

final class RecordedGmailTransport implements GmailApiTransport
{
    /** @var list<array{string, array<string, bool|int|string>}> */
    public array $requests = [];

    /** @param list<array<string, mixed>> $responses */
    public function __construct(private array $responses) {}

    public function get(string $path, array $query = []): array
    {
        $this->requests[] = [$path, $query];

        return array_shift($this->responses) ?? [];
    }
}

function gmailApiMessage(string $id, string $historyId, array $labels = ['INBOX']): array
{
    return [
        'id' => $id,
        'threadId' => 'thread-'.$id,
        'historyId' => $historyId,
        'internalDate' => '1787911200000',
        'labelIds' => $labels,
        'payload' => [
            'mimeType' => 'text/plain',
            'headers' => [['name' => 'Subject', 'value' => 'Message '.$id]],
            'body' => ['data' => rtrim(strtr(base64_encode('Body '.$id), '+/', '-_'), '=')],
        ],
    ];
}

it('performs a paged Gmail full sync and switches to a durable history checkpoint', function (): void {
    $transport = new RecordedGmailTransport([
        ['messages' => [['id' => 'm1']], 'nextPageToken' => 'page-2'],
        gmailApiMessage('m1', '120'),
        ['messages' => [['id' => 'm2']]],
        gmailApiMessage('m2', '110'),
    ]);
    $source = new GmailApiSource('gmail:alice', 'alice@example.com', $transport, true);

    $first = $source->page(null, 25);
    $second = $source->page($first->checkpoint, 25);
    $checkpoint = GmailCheckpoint::decode($second->checkpoint);

    expect($first->hasMore)->toBeTrue()
        ->and($first->messages[0]->change)->toBe(EmailChangeKind::Created)
        ->and($second->hasMore)->toBeFalse()
        ->and($second->messages[0]->providerId)->toBe('m2')
        ->and($checkpoint->mode)->toBe('history')
        ->and($checkpoint->historyId)->toBe('120')
        ->and($transport->requests)->toBe([
            ['users/alice%40example.com/messages', ['maxResults' => 25, 'includeSpamTrash' => true]],
            ['users/alice%40example.com/messages/m1', ['format' => 'full']],
            ['users/alice%40example.com/messages', ['maxResults' => 25, 'includeSpamTrash' => true, 'pageToken' => 'page-2']],
            ['users/alice%40example.com/messages/m2', ['format' => 'full']],
        ]);
});

it('uses the Gmail profile history ID when a full sync finds an empty mailbox', function (): void {
    $transport = new RecordedGmailTransport([
        ['messages' => []],
        ['historyId' => '901'],
    ]);
    $page = (new GmailApiSource('gmail:empty', 'me', $transport))->page(null, 10);

    expect($page->messages)->toBe([])
        ->and(GmailCheckpoint::decode($page->checkpoint)->historyId)->toBe('901')
        ->and($transport->requests[1][0])->toBe('users/me/profile');
});

it('maps Gmail history changes and advances to the response history ID', function (): void {
    $transport = new RecordedGmailTransport([
        [
            'history' => [
                ['id' => '121', 'messagesAdded' => [['message' => ['id' => 'm3']]]],
                ['id' => '122', 'labelsRemoved' => [['message' => ['id' => 'm1']]]],
                ['id' => '123', 'messagesDeleted' => [['message' => ['id' => 'm2']]]],
            ],
            'historyId' => '125',
        ],
        gmailApiMessage('m3', '121'),
        gmailApiMessage('m1', '122', ['ARCHIVE']),
    ]);
    $source = new GmailApiSource('gmail:alice', 'me', $transport);
    $page = $source->page(GmailCheckpoint::history('120')->encode(), 100);

    expect(array_map(static fn ($message): string => $message->change->value, $page->messages))
        ->toBe(['created', 'updated', 'deleted'])
        ->and($page->messages[2]->providerRevision)->toBe('123')
        ->and(GmailCheckpoint::decode($page->checkpoint)->historyId)->toBe('125')
        ->and($transport->requests[0])->toBe([
            'users/me/history',
            ['startHistoryId' => '120', 'maxResults' => 100],
        ]);
});

it('sends a resolved bearer token and reports expired Gmail history', function (): void {
    $history = [];
    $handler = new MockHandler([new Response(404, [], '{"error":{"message":"History expired"}}')]);
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($history));
    $tokens = new class implements GmailTokenResolver
    {
        public function resolve(string $sourceInstallationId): GmailTokenSecret
        {
            expect($sourceInstallationId)->toBe('installation-1');

            return new GmailTokenSecret('gmail-token');
        }
    };
    $transport = new HttpGmailApiTransport('installation-1', $tokens, new Client(['handler' => $stack]));

    expect(fn () => $transport->get('users/me/history', ['startHistoryId' => '120']))
        ->toThrow(GmailHistoryExpired::class, 'Start a new full synchronization.');

    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer gmail-token')
        ->and((string) $history[0]['request']->getUri())->toContain('startHistoryId=120');
});
