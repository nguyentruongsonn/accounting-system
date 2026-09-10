<?php

namespace App\Services;

use JsonException;

/** Stable SHA-256 hash of an owner-approved statement-form catalogue. */
final class StatutoryFinancialStatementDefinitionContractHasher
{
    /** @param array<string,mixed> $contract */
    public function hash(array $contract): string
    {
        try {
            return hash('sha256', json_encode($this->normalise($contract), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        } catch (JsonException $exception) {
            throw new \LogicException('Statutory statement definition contracts must be JSON-serializable.', previous: $exception);
        }
    }

    private function normalise(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        foreach ($value as $key => $item) $value[$key] = $this->normalise($item);
        if (! array_is_list($value)) ksort($value, SORT_STRING);
        return $value;
    }
}
