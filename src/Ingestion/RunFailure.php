<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

final readonly class RunFailure
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $kind,
        public string $message,
        public bool $retryable,
        public array $details = [],
        public FailureOrigin $origin = FailureOrigin::Domain,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'message' => $this->message,
            'retryable' => $this->retryable,
            'details' => $this->details,
            'origin' => $this->origin->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            kind: (string) ($data['kind'] ?? 'unknown'),
            message: (string) ($data['message'] ?? ''),
            retryable: (bool) ($data['retryable'] ?? false),
            details: is_array($data['details'] ?? null) ? $data['details'] : [],
            origin: FailureOrigin::tryFrom((string) ($data['origin'] ?? 'domain')) ?? FailureOrigin::Domain,
        );
    }

    public function recoveryAction(): RecoveryAction
    {
        if (in_array($this->kind, ['authentication', 'authentication_blocked'], true)) {
            return RecoveryAction::ProvideCredentials;
        }

        if ($this->kind === 'rate_limited' || $this->retryable) {
            return RecoveryAction::Retry;
        }

        return RecoveryAction::UserAction;
    }
}
