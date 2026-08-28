<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class QueueDispatchPolicy
{
    public function __construct(
        public QueueClass $queue,
        public int $priority,
        public string $concurrencyKey,
        public int $maxConcurrency,
        public string $rateLimitKey,
        public int $maxPerMinute,
    ) {
        if ($priority < 0 || $priority > 100) {
            throw new InvalidArgumentException('Queue priority must be between 0 and 100.');
        }

        if (trim($concurrencyKey) === '' || trim($rateLimitKey) === '') {
            throw new InvalidArgumentException('Queue limits require stable concurrency and rate-limit keys.');
        }

        if ($maxConcurrency < 1 || $maxPerMinute < 1) {
            throw new InvalidArgumentException('Queue limits must be positive.');
        }
    }

    public static function forRun(IngestionRun $run): self
    {
        $scope = $run->sourceInstallationId ?? $run->sourceReference;

        return new self(
            QueueClass::for($run->capability),
            50,
            'source-installation:'.$scope,
            1,
            'source-installation:'.$scope,
            60,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'queue' => $this->queue->value,
            'priority' => $this->priority,
            'concurrency_key' => $this->concurrencyKey,
            'max_concurrency' => $this->maxConcurrency,
            'rate_limit_key' => $this->rateLimitKey,
            'max_per_minute' => $this->maxPerMinute,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            QueueClass::from((string) $value['queue']),
            (int) $value['priority'],
            (string) $value['concurrency_key'],
            (int) $value['max_concurrency'],
            (string) $value['rate_limit_key'],
            (int) $value['max_per_minute'],
        );
    }
}
