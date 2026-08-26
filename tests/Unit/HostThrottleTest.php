<?php

declare(strict_types=1);

use Sifrious\Aleph\Tests\Fixtures\FakeClock;
use Sifrious\Aleph\Web\HostThrottle;

it('does not delay the first request to a host', function (): void {
    $clock = new FakeClock;

    (new HostThrottle($clock))->wait('ahsd.test', 2.0);

    expect($clock->slept)->toBe([]);
});

it('waits out the remainder of the delay between requests to one host', function (): void {
    $clock = new FakeClock;
    $throttle = new HostThrottle($clock);

    $throttle->wait('ahsd.test', 2.0);
    $clock->advance(0.5);
    $throttle->wait('ahsd.test', 2.0);

    expect($clock->slept)->toBe([1.5]);
});

it('does not wait when the delay has already elapsed', function (): void {
    $clock = new FakeClock;
    $throttle = new HostThrottle($clock);

    $throttle->wait('ahsd.test', 2.0);
    $clock->advance(3.0);
    $throttle->wait('ahsd.test', 2.0);

    expect($clock->slept)->toBe([]);
});

it('throttles each host independently', function (): void {
    $clock = new FakeClock;
    $throttle = new HostThrottle($clock);

    $throttle->wait('ahsd.test', 2.0);
    $throttle->wait('hs.ahsd.test', 2.0);

    expect($clock->slept)->toBe([]);
});

it('never waits when the delay is zero', function (): void {
    $clock = new FakeClock;
    $throttle = new HostThrottle($clock);

    $throttle->wait('ahsd.test', 0.0);
    $throttle->wait('ahsd.test', 0.0);

    expect($clock->slept)->toBe([]);
});
