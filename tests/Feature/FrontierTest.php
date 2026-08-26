<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sifrious\Aleph\Web\CanonicalUrl;
use Sifrious\Aleph\Web\CrawlRuns;
use Sifrious\Aleph\Web\DiscoveryOrigin;
use Sifrious\Aleph\Web\FetchFailure;
use Sifrious\Aleph\Web\FetchResult;
use Sifrious\Aleph\Web\FrontierFactory;
use Sifrious\Aleph\Web\FrontierState;
use Sifrious\Aleph\Web\SkipReason;
use Sifrious\Aleph\Web\WebSource;

beforeEach(function (): void {
    $this->source = WebSource::fromArray('test', webSource());
    $this->run = app(CrawlRuns::class)->start($this->source);
    $this->frontier = app(FrontierFactory::class)->for($this->source, $this->run);
    $this->canonicalizer = $this->source->canonicalizer();
});

function canonical(string $value): CanonicalUrl
{
    return test()->canonicalizer->canonicalize($value);
}

function enqueue(string $value, int $depth = 0, ?int $parentId = null): ?int
{
    return test()->frontier->record(
        canonical($value),
        $value,
        $depth,
        $parentId === null ? DiscoveryOrigin::Seed : DiscoveryOrigin::Link,
        $parentId,
        FrontierState::Pending,
    );
}

it('accepts a new canonical url once and rejects the duplicate', function (): void {
    $first = enqueue('https://ahsd.test/a');

    expect($first)->toBeInt();

    expect(enqueue('https://ahsd.test/a'))->toBeNull()
        ->and(enqueue('https://ahsd.test/a?utm_source=x'))->toBeNull()
        ->and(enqueue('https://ahsd.test/a#section'))->toBeNull();

    expect($this->frontier->countByState(FrontierState::Pending))->toBe(1);
});

it('claims pending candidates breadth first in insertion order', function (): void {
    enqueue('https://ahsd.test/deep', 2);
    enqueue('https://ahsd.test/b', 1);
    enqueue('https://ahsd.test/a', 1);
    enqueue('https://ahsd.test/', 0);

    $claimed = [];

    while (($candidate = $this->frontier->claimNext()) !== null) {
        $claimed[] = $candidate->url->value;
        $this->frontier->markFetched($candidate, FetchResult::response(
            $candidate->url->value,
            $candidate->url->value,
            200,
        ));
    }

    expect($claimed)->toBe([
        'https://ahsd.test/',
        'https://ahsd.test/b',
        'https://ahsd.test/a',
        'https://ahsd.test/deep',
    ]);
});

it('moves a claimed candidate through fetching to fetched', function (): void {
    enqueue('https://ahsd.test/a');

    $candidate = $this->frontier->claimNext();

    expect($this->frontier->countByState(FrontierState::Fetching))->toBe(1)
        ->and($this->frontier->countByState(FrontierState::Pending))->toBe(0);

    $this->frontier->markFetched($candidate, FetchResult::response(
        'https://ahsd.test/a',
        'https://ahsd.test/final',
        200,
        'text/html',
    ));

    expect($this->frontier->countByState(FrontierState::Fetched))->toBe(1)
        ->and($this->frontier->countByState(FrontierState::Fetching))->toBe(0);
});

it('moves a claimed candidate to failed while preserving the failure', function (): void {
    enqueue('https://ahsd.test/a');

    $candidate = $this->frontier->claimNext();

    $this->frontier->markFailed($candidate, FetchResult::failed(
        'https://ahsd.test/a',
        FetchFailure::Timeout,
        'Timed out after 10s.',
    ));

    expect($this->frontier->countByState(FrontierState::Failed))->toBe(1);

    $row = DB::table('aleph_frontier_candidates')->first();

    expect($row->failure)->toBe('timeout')
        ->and($row->failure_message)->toBe('Timed out after 10s.')
        ->and($row->fetched_at)->not->toBeNull();
});

it('returns candidates stranded in fetching back to pending', function (): void {
    enqueue('https://ahsd.test/a');
    $this->frontier->claimNext();

    expect($this->frontier->countByState(FrontierState::Fetching))->toBe(1);

    expect($this->frontier->releaseClaimed())->toBe(1)
        ->and($this->frontier->countByState(FrontierState::Pending))->toBe(1);
});

it('never claims a skipped candidate', function (): void {
    $this->frontier->record(
        canonical('https://facebook.test/ahsd'),
        'https://facebook.test/ahsd',
        1,
        DiscoveryOrigin::Link,
        null,
        FrontierState::Skipped,
        SkipReason::ExternalHost,
    );

    expect($this->frontier->claimNext())->toBeNull()
        ->and($this->frontier->skippedByReason())->toBe(['external_host' => 1]);
});

it('reports an empty frontier before anything is recorded', function (): void {
    expect($this->frontier->isEmpty())->toBeTrue();

    enqueue('https://ahsd.test/a');

    expect($this->frontier->isEmpty())->toBeFalse();
});
