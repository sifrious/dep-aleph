<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Email;

use InvalidArgumentException;

final readonly class GmailCheckpoint
{
    private function __construct(
        public string $mode,
        public ?string $historyId,
        public ?string $pageToken,
    ) {}

    public static function full(?string $historyId = null, ?string $pageToken = null): self
    {
        return new self('full', $historyId, $pageToken);
    }

    public static function history(string $historyId, ?string $pageToken = null): self
    {
        if ($historyId === '') {
            throw new InvalidArgumentException('A Gmail history checkpoint requires a history ID.');
        }

        return new self('history', $historyId, $pageToken);
    }

    public static function decode(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::full();
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        $state = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($state) || ($state['version'] ?? null) !== 1
            || ! in_array($state['mode'] ?? null, ['full', 'history'], true)
            || (isset($state['history_id']) && ! is_string($state['history_id']))
            || (isset($state['page_token']) && ! is_string($state['page_token']))
        ) {
            throw new InvalidArgumentException('Gmail checkpoint is invalid or unsupported.');
        }

        $mode = $state['mode'];
        $historyId = $state['history_id'] ?? null;

        if ($mode === 'history' && (! is_string($historyId) || $historyId === '')) {
            throw new InvalidArgumentException('A Gmail history checkpoint requires a history ID.');
        }

        return new self($mode, $historyId, $state['page_token'] ?? null);
    }

    public function encode(): string
    {
        $json = json_encode(array_filter([
            'version' => 1,
            'mode' => $this->mode,
            'history_id' => $this->historyId,
            'page_token' => $this->pageToken,
        ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
