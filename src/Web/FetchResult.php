<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Web;

use DateTimeImmutable;

final readonly class FetchResult
{
    /**
     * @param  list<string>  $redirectChain
     */
    private function __construct(
        public string $requestedUrl,
        public ?string $finalUrl,
        public ?int $status,
        public ?string $contentType,
        public ?string $body,
        public array $redirectChain,
        public ?FetchFailure $failure,
        public ?string $failureMessage,
        public DateTimeImmutable $retrievedAt,
    ) {}

    /**
     * @param  list<string>  $redirectChain
     */
    public static function response(
        string $requestedUrl,
        string $finalUrl,
        int $status,
        ?string $contentType = null,
        ?string $body = null,
        array $redirectChain = [],
        ?DateTimeImmutable $retrievedAt = null,
    ): self {
        return new self(
            $requestedUrl,
            $finalUrl,
            $status,
            $contentType,
            $body,
            $redirectChain,
            null,
            null,
            $retrievedAt ?? new DateTimeImmutable,
        );
    }

    /**
     * @param  list<string>  $redirectChain
     */
    public static function failed(
        string $requestedUrl,
        FetchFailure $failure,
        ?string $message = null,
        array $redirectChain = [],
        ?DateTimeImmutable $retrievedAt = null,
    ): self {
        return new self(
            $requestedUrl,
            null,
            null,
            null,
            null,
            $redirectChain,
            $failure,
            $message,
            $retrievedAt ?? new DateTimeImmutable,
        );
    }

    public function retrieved(): bool
    {
        return $this->failure === null;
    }

    public function isOk(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
