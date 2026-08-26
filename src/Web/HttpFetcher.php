<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\StreamInterface;

final readonly class HttpFetcher implements Fetcher
{
    public function __construct(
        private Factory $http,
        private FetchPolicy $policy,
        private HostThrottle $throttle,
        private RobotsPolicy $robots,
    ) {}

    public function fetch(FetchRequest $request): FetchResult
    {
        if (! $this->robots->allows($request->url)) {
            return FetchResult::failed(
                $request->url->value,
                FetchFailure::RobotsDisallowed,
                'Retrieval is disallowed by the robots policy for this host.',
            );
        }

        $result = $this->throttledAttempt($request);

        for ($retry = 0; $retry < $this->policy->retries; $retry++) {
            if (! $this->retryable($result)) {
                return $result;
            }

            $result = $this->throttledAttempt($request);
        }

        return $result;
    }

    private function throttledAttempt(FetchRequest $request): FetchResult
    {
        $this->throttle->wait(
            $request->url->host,
            max($this->policy->delaySeconds, $this->robots->crawlDelay($request->url) ?? 0.0),
        );

        return $this->attempt($request);
    }

    private function attempt(FetchRequest $request): FetchResult
    {
        $requested = $request->url->value;

        try {
            $response = $this->http
                ->withHeaders(['User-Agent' => $this->policy->userAgent])
                ->withOptions([
                    'allow_redirects' => [
                        'max' => $this->policy->maxRedirects,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['http', 'https'],
                        'track_redirects' => true,
                    ],
                    'stream' => true,
                ])
                ->connectTimeout($this->policy->connectTimeout)
                ->timeout($this->policy->timeout)
                ->send($request->method->value, $requested);
        } catch (TooManyRedirectsException $e) {
            return FetchResult::failed($requested, FetchFailure::TooManyRedirects, $e->getMessage());
        } catch (ConnectionException $e) {
            return FetchResult::failed($requested, $this->classify($e->getMessage()), $e->getMessage());
        } catch (TransferException $e) {
            return FetchResult::failed($requested, FetchFailure::ConnectionFailed, $e->getMessage());
        }

        $psr = $response->toPsrResponse();
        $chain = array_values($psr->getHeader('X-Guzzle-Redirect-History'));
        $final = $chain === [] ? $requested : (string) end($chain);
        $declared = $psr->getHeaderLine('Content-Length');

        if (ctype_digit($declared) && (int) $declared > $this->policy->maxResponseBytes) {
            return FetchResult::failed(
                $requested,
                FetchFailure::TooLarge,
                "Response declared {$declared} bytes.",
                $chain,
            );
        }

        $body = $this->readBounded($psr->getBody());

        if ($body === null) {
            return FetchResult::failed(
                $requested,
                FetchFailure::TooLarge,
                "Response exceeded {$this->policy->maxResponseBytes} bytes.",
                $chain,
            );
        }

        return FetchResult::response(
            $requested,
            $final,
            $response->status(),
            $psr->getHeaderLine('Content-Type') === '' ? null : $psr->getHeaderLine('Content-Type'),
            $request->method === HttpMethod::Head ? null : $body,
            $chain,
        );
    }

    private function readBounded(StreamInterface $stream): ?string
    {
        $body = '';

        while (! $stream->eof()) {
            $body .= $stream->read(8192);

            if (strlen($body) > $this->policy->maxResponseBytes) {
                $stream->close();

                return null;
            }
        }

        return $body;
    }

    private function classify(string $message): FetchFailure
    {
        $message = strtolower($message);

        return str_contains($message, 'timed out') || str_contains($message, 'timeout')
            ? FetchFailure::Timeout
            : FetchFailure::ConnectionFailed;
    }

    private function retryable(FetchResult $result): bool
    {
        return $result->failure === FetchFailure::ConnectionFailed
            || $result->failure === FetchFailure::Timeout;
    }
}
