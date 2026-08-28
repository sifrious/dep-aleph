<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Tests\Fixtures;

use Sifrious\Aleph\Web\Fetcher;
use Sifrious\Aleph\Web\FetchFailure;
use Sifrious\Aleph\Web\FetchRequest;
use Sifrious\Aleph\Web\FetchResult;

final class FakeSite implements Fetcher
{
    /**
     * @var array<string, array{status: int, contentType: string, body: string}>
     */
    private array $pages = [];

    /**
     * @var array<string, string>
     */
    private array $redirects = [];

    /**
     * @var array<string, FetchFailure>
     */
    private array $failures = [];

    /**
     * @var list<string>
     */
    public array $requested = [];

    /**
     * @param  list<string>  $links
     */
    public function page(
        string $url,
        array $links = [],
        int $status = 200,
        string $contentType = 'text/html; charset=utf-8',
        ?string $body = null,
    ): self {
        $anchors = implode('', array_map(
            fn (string $link): string => '<a href="'.htmlspecialchars($link, ENT_QUOTES).'">x</a>',
            $links,
        ));
        $this->pages[$url] = [
            'status' => $status,
            'contentType' => $contentType,
            'body' => $body ?? "<html><body>{$url}{$anchors}</body></html>",
        ];

        return $this;
    }

    public function redirect(string $from, string $to): self
    {
        $this->redirects[$from] = $to;

        return $this;
    }

    public function fails(string $url, FetchFailure $failure = FetchFailure::ConnectionFailed): self
    {
        $this->failures[$url] = $failure;

        return $this;
    }

    public function fetch(FetchRequest $request): FetchResult
    {
        $requested = $request->url->value;
        $this->requested[] = $requested;

        if (isset($this->failures[$requested])) {
            return FetchResult::failed($requested, $this->failures[$requested], 'Fake transport failure.');
        }

        $chain = [];
        $current = $requested;

        while (isset($this->redirects[$current])) {
            if (count($chain) >= 5) {
                return FetchResult::failed($requested, FetchFailure::TooManyRedirects, 'Fake redirect loop.', $chain);
            }

            $current = $this->redirects[$current];
            $chain[] = $current;
        }

        $page = $this->pages[$current] ?? null;

        if ($page === null) {
            return FetchResult::response($requested, $current, 404, 'text/html', '', $chain);
        }

        return FetchResult::response(
            $requested,
            $current,
            $page['status'],
            $page['contentType'],
            $page['body'],
            $chain,
        );
    }
}
