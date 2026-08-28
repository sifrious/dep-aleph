<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector;

final readonly class Rejection
{
    public const UNKNOWN_CONNECTOR = 'unknown_connector';

    public const CAPABILITY_NOT_SUPPORTED = 'capability_not_supported';

    public const CAPABILITY_NOT_DISPATCHABLE = 'capability_not_dispatchable';

    /**
     * @param  list<string>  $supported
     */
    public function __construct(
        public string $reason,
        public string $connectorId,
        public ?string $capability,
        public array $supported,
        public string $message,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'connector' => $this->connectorId,
            'capability' => $this->capability,
            'supported' => $this->supported,
            'message' => $this->message,
        ];
    }
}
