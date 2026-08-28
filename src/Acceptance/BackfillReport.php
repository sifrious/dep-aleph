<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Acceptance;

final readonly class BackfillReport
{
    /**
     * @param  list<string>  $failures
     */
    public function __construct(
        public int $examined,
        public int $accepted,
        public int $replayed,
        public int $rejected,
        public int $failed,
        public array $failures = [],
    ) {}

    public function settled(): int
    {
        return $this->accepted + $this->replayed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'examined' => $this->examined,
            'accepted' => $this->accepted,
            'replayed' => $this->replayed,
            'rejected' => $this->rejected,
            'failed' => $this->failed,
            'failures' => $this->failures,
        ];
    }
}
