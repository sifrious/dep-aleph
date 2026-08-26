<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use InvalidArgumentException;

final readonly class FetchPolicy
{
    public function __construct(
        public string $userAgent,
        public float $connectTimeout = 5.0,
        public float $timeout = 15.0,
        public int $maxResponseBytes = 5_242_880,
        public int $maxRedirects = 5,
        public float $delaySeconds = 1.0,
        public int $retries = 1,
        public bool $respectRobots = true,
    ) {
        if ($userAgent === '') {
            throw new InvalidArgumentException('A crawler user agent is required.');
        }

        if ($maxResponseBytes < 1) {
            throw new InvalidArgumentException('The response size limit must be positive.');
        }

        if ($retries < 0) {
            throw new InvalidArgumentException('The retry count cannot be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            userAgent: is_string($config['user_agent'] ?? null) ? $config['user_agent'] : 'AlephCrawler',
            connectTimeout: self::number($config['connect_timeout'] ?? null, 5.0),
            timeout: self::number($config['timeout'] ?? null, 15.0),
            maxResponseBytes: is_int($config['max_response_bytes'] ?? null) ? $config['max_response_bytes'] : 5_242_880,
            maxRedirects: is_int($config['max_redirects'] ?? null) ? $config['max_redirects'] : 5,
            delaySeconds: self::number($config['delay_ms'] ?? null, 1000.0) / 1000,
            retries: is_int($config['retries'] ?? null) ? $config['retries'] : 1,
            respectRobots: ! isset($config['respect_robots']) || $config['respect_robots'] === true,
        );
    }

    private static function number(mixed $value, float $default): float
    {
        return is_int($value) || is_float($value) ? (float) $value : $default;
    }
}
