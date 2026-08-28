<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Health;

final readonly class HealthRemediation
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}

    /**
     * @return array{code: string, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }
}
