<?php

namespace App\Services;

use JsonException;

/** Stable hash of the report definition which is approved and published. */
final class FinancialReportDefinitionContractHasher
{
    /** @param array<string,mixed> $definition */
    public function hash(array $definition): string
    {
        try {
            return hash('sha256', json_encode($this->normalise($definition), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        } catch (JsonException $exception) {
            throw new \LogicException('Financial report definition contracts must be JSON-serializable.', previous: $exception);
        }
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    public function equivalent(array $left, array $right): bool
    {
        return hash_equals($this->hash($left), $this->hash($right));
    }

    private function normalise(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        foreach ($value as $key => $item) $value[$key] = $this->normalise($item);
        if (! array_is_list($value)) ksort($value, SORT_STRING);
        return $value;
    }
}
