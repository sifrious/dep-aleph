<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Values;

final readonly class WebhookDelivery
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $sourceReference,
        public array $headers,
        public string $body,
        public ?string $signature = null,
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
