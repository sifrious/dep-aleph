<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Assertion;

use DateTimeImmutable;
use Sifrious\Funes\Assertion\AbstractHistoricalAssertion;
use Sifrious\Funes\Assertion\DeclaredHistoricalAssertion;
use Sifrious\Funes\Assertion\InferredHistoricalAssertion;
use Sifrious\Funes\Assertion\ObservedHistoricalAssertion;
use Sifrious\Funes\Value\SourceLocator;
use Sifrious\ReferenceContract\CrossPackageReference;

final readonly class CanonicalArrayAssertionAdapter implements HistoricalAssertionAdapter
{
    public function __construct(private string $provider)
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $provider) !== 1) {
            throw new AssertionNormalizationException('Provider names must be stable lowercase identifiers.');
        }
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function normalize(ProviderAssertionInput $input): AssertionNormalization
    {
        if ($input->provider !== $this->provider) {
            throw new UnsupportedAssertionProvider("The {$this->provider} adapter cannot normalize {$input->provider} input.");
        }

        $payload = $input->payload;
        $required = ['id', 'type', 'subject', 'predicate', 'value', 'source', 'observed_at'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new IncompleteAssertionPayload("Provider assertion payload is missing {$field}.");
            }
        }

        try {
            $subject = CrossPackageReference::fromArray($this->array($payload, 'subject'));
            $source = $this->array($payload, 'source');
            $arguments = [
                'id' => $this->string($payload, 'id'),
                'subject' => $subject,
                'predicate' => $this->string($payload, 'predicate'),
                'value' => $payload['value'],
                'source' => new SourceLocator($this->string($source, 'source_reference'), $this->string($source, 'source_name'), $this->string($source, 'resource_reference')),
                'tenant' => $input->authorization->tenant,
                'occurredAt' => $this->time($payload['occurred_at'] ?? null),
                'observedAt' => $this->time($payload['observed_at']) ?? throw new AssertionNormalizationException('observed_at is required.'),
                'recordedAt' => $this->time($payload['recorded_at'] ?? null) ?? new DateTimeImmutable,
                'provenance' => $input->rawSource,
                'evidence' => array_map(
                    static fn (array $value): CrossPackageReference => CrossPackageReference::fromArray($value),
                    $this->evidence($payload['evidence'] ?? []),
                ),
            ];
            $assertion = $this->assertion($this->string($payload, 'type'), $arguments);
        } catch (AssertionNormalizationException|UnsupportedAssertionType $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new AssertionNormalizationException('Provider assertion payload is malformed.', previous: $exception);
        }

        $known = [...$required, 'occurred_at', 'recorded_at', 'evidence', 'confidence'];

        return new AssertionNormalization(
            $assertion,
            $input->rawSource,
            array_values(array_diff(array_keys($payload), $known)),
            isset($payload['confidence']) ? (float) $payload['confidence'] : null,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function assertion(string $type, array $arguments): AbstractHistoricalAssertion
    {
        return match ($type) {
            'observed' => new ObservedHistoricalAssertion(...$arguments),
            'declared' => new DeclaredHistoricalAssertion(...$arguments),
            'inferred' => new InferredHistoricalAssertion(...$arguments),
            default => throw new UnsupportedAssertionType("Unsupported assertion type {$type}."),
        };
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key): string
    {
        return is_string($values[$key] ?? null) ? $values[$key] : throw new AssertionNormalizationException("{$key} must be a string.");
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function array(array $values, string $key): array
    {
        return is_array($values[$key] ?? null) ? $values[$key] : throw new AssertionNormalizationException("{$key} must be an object.");
    }

    private function time(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable(is_string($value) ? $value : throw new AssertionNormalizationException('Timestamps must be strings.'));
    }

    /** @return list<array<string, mixed>> */
    private function evidence(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new AssertionNormalizationException('evidence must be a list of references.');
        }

        foreach ($value as $reference) {
            if (! is_array($reference)) {
                throw new AssertionNormalizationException('Each evidence entry must be a reference object.');
            }
        }

        return $value;
    }
}
