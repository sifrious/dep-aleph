<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\GitHub;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class GitHubActivity
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public GitHubActivityKind $kind,
        public string $repository,
        public string $nodeId,
        public DateTimeImmutable $updatedAt,
        public array $payload,
    ) {
        if (trim($repository) === '' || trim($nodeId) === '') {
            throw new InvalidArgumentException('GitHub activity requires repository coordinates and a stable node ID.');
        }
    }

    public function resourceReference(): string
    {
        return 'github:'.strtolower($this->repository).'/'.$this->kind->value.'/'.$this->nodeId;
    }

    public function revision(): string
    {
        return $this->updatedAt->format('Y-m-d\TH:i:s.uP');
    }

    public function contents(): string
    {
        return json_encode($this->canonicalize($this->payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalize(...), $value);
    }
}
