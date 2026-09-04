<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Bibliography;

use InvalidArgumentException;

final readonly class ContentIdentity
{
    /** @var array<string, string> */
    public array $hashes;

    public string $sha256;

    /**
     * @param  array<string, string>  $hashes
     */
    public function __construct(array $hashes, public ?int $byteSize = null)
    {
        if ($byteSize !== null && $byteSize < 0) {
            throw new InvalidArgumentException('Content byte size cannot be negative.');
        }

        $normalized = [];

        foreach ($hashes as $algorithm => $digest) {
            $algorithm = strtolower(trim((string) $algorithm));
            $digest = strtolower(trim($digest));

            if ($algorithm === '' || $digest === '' || ! ctype_xdigit($digest)) {
                throw new InvalidArgumentException('Content hashes require named algorithms and hexadecimal digests.');
            }

            $normalized[$algorithm] = $digest;
        }

        if (! isset($normalized['sha256']) || strlen($normalized['sha256']) !== 64) {
            throw new InvalidArgumentException('Content identity requires a 64-character hexadecimal SHA-256 digest.');
        }

        ksort($normalized, SORT_STRING);
        $this->hashes = $normalized;
        $this->sha256 = $normalized['sha256'];
    }

    public static function sha256(string $digest, ?int $byteSize = null): self
    {
        if (preg_match('/\A[0-9a-fA-F]{64}\z/', $digest) !== 1) {
            throw new InvalidArgumentException('A SHA-256 digest must contain 64 hexadecimal characters.');
        }

        return new self(['sha256' => $digest], $byteSize);
    }

    public function key(): string
    {
        return $this->sha256;
    }
}
