<?php

declare(strict_types=1);

use Sifrious\Aleph\Acceptance\HistoricalAssertionAcceptance;
use Sifrious\Aleph\Assertion\CanonicalArrayAssertionAdapter;
use Sifrious\Aleph\Assertion\HistoricalAssertionAdapters;
use Sifrious\Aleph\Assertion\IncompleteAssertionPayload;
use Sifrious\Aleph\Assertion\ProviderAssertionInput;
use Sifrious\Aleph\Assertion\UnsupportedAssertionProvider;
use Sifrious\Aleph\Assertion\UnsupportedAssertionType;
use Sifrious\AuthorizationContract\ActorContext;
use Sifrious\AuthorizationContract\ActorKind;
use Sifrious\AuthorizationContract\AuthorizationContext;
use Sifrious\AuthorizationContract\TenantScope;
use Sifrious\Funes\Assertion\AssertionDisposition;
use Sifrious\Funes\Assertion\HistoricalAssertionStore;
use Sifrious\ReferenceContract\CrossPackageReference;

function assertionReference(string $type, string $id): CrossPackageReference
{
    return new CrossPackageReference('sifrious/aleph', $type, $id);
}

function assertionAuthorization(): AuthorizationContext
{
    return new AuthorizationContext(
        new ActorContext(assertionReference('actor', 'mary'), ActorKind::Human),
        TenantScope::forTenant('account', assertionReference('account', 'mme')),
    );
}

/** @param array<string, mixed> $changes */
function assertionInput(string $provider = 'claude', array $changes = []): ProviderAssertionInput
{
    $raw = assertionReference('provider-record', $provider.':claim-1');
    $payload = [
        'id' => $provider.':claim-1',
        'type' => 'observed',
        'subject' => assertionReference('conversation', 'conv-1')->toArray(),
        'predicate' => 'message.summary',
        'value' => 'The deploy passed.',
        'source' => [
            'source_reference' => $provider.':account/main',
            'source_name' => ucfirst($provider),
            'resource_reference' => $provider.':message/1',
        ],
        'observed_at' => '2026-09-02T12:00:00.123456-04:00',
        'recorded_at' => '2026-09-02T16:00:01.123456+00:00',
    ];

    return new ProviderAssertionInput($provider, array_replace($payload, $changes), $raw, assertionAuthorization());
}

function assertionGateway(string ...$providers): HistoricalAssertionAcceptance
{
    $registry = new HistoricalAssertionAdapters(array_map(
        static fn (string $provider): CanonicalArrayAssertionAdapter => new CanonicalArrayAssertionAdapter($provider),
        $providers,
    ));

    return new HistoricalAssertionAcceptance($registry, app(HistoricalAssertionStore::class));
}

it('discovers tagged provider adapters through the package container', function (): void {
    app()->bind('test.assertion-adapter.claude', fn (): CanonicalArrayAssertionAdapter => new CanonicalArrayAssertionAdapter('claude'));
    app()->tag('test.assertion-adapter.claude', 'aleph.historical_assertion_adapters');

    expect(app(HistoricalAssertionAdapters::class)->providers())->toBe(['claude']);
});

it('discovers an adapter by provider and emits a canonical Funes assertion', function (): void {
    $normalized = assertionGateway('claude')->normalize(assertionInput());

    expect($normalized->assertion->predicate())->toBe('message.summary')
        ->and($normalized->assertion->tenant()->equals(assertionAuthorization()->tenant))->toBeTrue()
        ->and($normalized->assertion->provenance()?->equals($normalized->rawSource))->toBeTrue()
        ->and($normalized->assertion->toArray())->not->toHaveKey('provider');
});

it('runs the same conformance fixture for different provider families', function (string $provider): void {
    $accepted = assertionGateway('claude', 'github')->submit(assertionInput($provider));

    expect($accepted->disposition)->toBe(AssertionDisposition::First)
        ->and($accepted->assertion->source()->sourceName)->toBe(ucfirst($provider));
})->with(['claude', 'github']);

it('makes identical provider replay idempotent', function (): void {
    $gateway = assertionGateway('claude');
    $first = $gateway->submit(assertionInput());
    $replay = $gateway->submit(assertionInput());

    expect($first->disposition)->toBe(AssertionDisposition::First)
        ->and($replay->disposition)->toBe(AssertionDisposition::Duplicate)
        ->and($replay->assertion->id)->toBe($first->assertion->id);
});

it('reports provider lossiness and confidence outside canonical state', function (): void {
    $normalized = assertionGateway('claude')->normalize(assertionInput(changes: [
        'confidence' => 0.82,
        'provider_model' => 'claude-sonnet',
    ]));

    expect($normalized->isLossy())->toBeTrue()
        ->and($normalized->omittedFields)->toBe(['provider_model'])
        ->and($normalized->confidence)->toBe(0.82)
        ->and($normalized->assertion->toArray())->not->toHaveKeys(['confidence', 'provider_model']);
});

it('rejects a partial payload with a named field', function (): void {
    $input = assertionInput();
    $payload = $input->payload;
    unset($payload['predicate']);

    assertionGateway('claude')->normalize(new ProviderAssertionInput('claude', $payload, $input->rawSource, $input->authorization));
})->throws(IncompleteAssertionPayload::class, 'missing predicate');

it('rejects an assertion type the provider cannot map', function (): void {
    assertionGateway('claude')->normalize(assertionInput(changes: ['type' => 'predicted']));
})->throws(UnsupportedAssertionType::class, 'Unsupported assertion type predicted');

it('fails explicitly when no provider adapter is registered', function (): void {
    assertionGateway('github')->normalize(assertionInput('claude'));
})->throws(UnsupportedAssertionProvider::class, 'No historical assertion adapter');
