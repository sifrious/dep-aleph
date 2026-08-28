<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class BackfillRange
{
    public function __construct(
        public string $format,
        public string $from,
        public string $to,
    ) {
        if (trim($format) === '' || trim($from) === '' || trim($to) === '') {
            throw new InvalidArgumentException('A backfill requires a bounded range and boundary format.');
        }
    }

    /**
     * @return array{format: string, from: string, to: string}
     */
    public function toArray(): array
    {
        return ['format' => $this->format, 'from' => $this->from, 'to' => $this->to];
    }
}
