<?php

declare(strict_types=1);

use Sifrious\Aleph\Web\RobotsRules;

it('allows everything when no group applies', function (): void {
    $rules = RobotsRules::parse("User-agent: googlebot\nDisallow: /\n", 'alephcrawler');

    expect($rules->allows('/anything'))->toBeTrue();
});

it('applies the wildcard group when no specific group matches', function (): void {
    $rules = RobotsRules::parse("User-agent: *\nDisallow: /private\n", 'alephcrawler');

    expect($rules->allows('/private/files'))->toBeFalse()
        ->and($rules->allows('/public'))->toBeTrue();
});

it('prefers a group naming the crawler over the wildcard group', function (): void {
    $text = <<<'ROBOTS'
    User-agent: *
    Disallow: /

    User-agent: alephcrawler
    Disallow: /admin
    ROBOTS;

    $rules = RobotsRules::parse($text, 'alephcrawler');

    expect($rules->allows('/news'))->toBeTrue()
        ->and($rules->allows('/admin/panel'))->toBeFalse();
});

it('treats an empty disallow as permission to crawl', function (): void {
    $rules = RobotsRules::parse("User-agent: *\nDisallow:\n", 'alephcrawler');

    expect($rules->allows('/anything'))->toBeTrue();
});

it('lets the longest matching rule win', function (): void {
    $text = <<<'ROBOTS'
    User-agent: *
    Disallow: /calendar
    Allow: /calendar/public
    ROBOTS;

    $rules = RobotsRules::parse($text, 'alephcrawler');

    expect($rules->allows('/calendar/private'))->toBeFalse()
        ->and($rules->allows('/calendar/public/october'))->toBeTrue();
});

it('prefers allow when two rules of equal length match', function (): void {
    $text = <<<'ROBOTS'
    User-agent: *
    Disallow: /docs
    Allow: /docs
    ROBOTS;

    expect(RobotsRules::parse($text, 'alephcrawler')->allows('/docs/a'))->toBeTrue();
});

it('honours wildcard and end anchor patterns', function (): void {
    $text = <<<'ROBOTS'
    User-agent: *
    Disallow: /*.pdf$
    Disallow: /print/*/preview
    ROBOTS;

    $rules = RobotsRules::parse($text, 'alephcrawler');

    expect($rules->allows('/files/report.pdf'))->toBeFalse()
        ->and($rules->allows('/files/report.pdf.html'))->toBeTrue()
        ->and($rules->allows('/print/2026/preview'))->toBeFalse();
});

it('reads a crawl delay', function (): void {
    $rules = RobotsRules::parse("User-agent: *\nCrawl-delay: 2.5\nDisallow: /x\n", 'alephcrawler');

    expect($rules->crawlDelay)->toBe(2.5);
});

it('ignores comments and blank lines', function (): void {
    $text = <<<'ROBOTS'
    # a comment

    User-agent: *   # trailing comment
    Disallow: /secret
    ROBOTS;

    expect(RobotsRules::parse($text, 'alephcrawler')->allows('/secret'))->toBeFalse();
});

it('groups consecutive user agent lines together', function (): void {
    $text = <<<'ROBOTS'
    User-agent: alephcrawler
    User-agent: otherbot
    Disallow: /shared
    ROBOTS;

    expect(RobotsRules::parse($text, 'alephcrawler')->allows('/shared'))->toBeFalse();
});

it('disallows everything when told to', function (): void {
    expect(RobotsRules::disallowAll()->allows('/'))->toBeFalse()
        ->and(RobotsRules::allowAll()->allows('/'))->toBeTrue();
});
