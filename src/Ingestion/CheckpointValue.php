<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Ingestion;

use InvalidArgumentException;

final readonly class CheckpointValue
{
    public function __construct(
        public string $format,
        public string $serializerVersion,
        public string $value,
        public CheckpointRule $rule = CheckpointRule::Replace,
        public ?int $position = null,
    ) {
        if (trim($format) === '' || trim($serializerVersion) === '') {
            throw new InvalidArgumentException('A checkpoint requires a format and serializer version.');
        }

        if ($rule === CheckpointRule::Monotonic && ($position === null || $position < 0)) {
            throw new InvalidArgumentException('A monotonic checkpoint requires a non-negative position.');
        }
    }
}
