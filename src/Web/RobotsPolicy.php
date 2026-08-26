<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use Illuminate\Http\Client\Factory;
use Throwable;

final class RobotsPolicy
{
    /**
     * @var array<string, RobotsRules>
     */
    private array $cache = [];

    public function __construct(
        private readonly Factory $http,
        private readonly FetchPolicy $policy,
    ) {}

    public function allows(CanonicalUrl $url): bool
    {
        if (! $this->policy->respectRobots) {
            return true;
        }

        return $this->rulesFor($url)->allows($url->path.($url->query !== null ? '?'.$url->query : ''));
    }

    public function crawlDelay(CanonicalUrl $url): ?float
    {
        if (! $this->policy->respectRobots) {
            return null;
        }

        return $this->rulesFor($url)->crawlDelay;
    }

    private function rulesFor(CanonicalUrl $url): RobotsRules
    {
        $origin = $url->scheme.'://'.$url->host.($url->port !== null ? ':'.$url->port : '');

        return $this->cache[$origin] ??= $this->load($origin);
    }

    private function load(string $origin): RobotsRules
    {
        try {
            $response = $this->http
                ->withHeaders(['User-Agent' => $this->policy->userAgent])
                ->withOptions(['allow_redirects' => ['max' => $this->policy->maxRedirects]])
                ->connectTimeout($this->policy->connectTimeout)
                ->timeout($this->policy->timeout)
                ->get($origin.'/robots.txt');
        } catch (Throwable) {
            return RobotsRules::disallowAll();
        }

        if ($response->serverError()) {
            return RobotsRules::disallowAll();
        }

        if (! $response->successful()) {
            return RobotsRules::allowAll();
        }

        return RobotsRules::parse($response->body(), $this->agentToken());
    }

    private function agentToken(): string
    {
        $token = strtok($this->policy->userAgent, '/ ');

        return $token === false ? $this->policy->userAgent : $token;
    }
}
