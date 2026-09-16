<?php

namespace App\Services;

use JsonException;

/**
 * Produces a stable fingerprint of the executable report contract. Contract
 * object keys are normalised recursively; list order remains significant.
 */
final class ManagementReportDefinitionContractHasher
{
    /**
     * @param  array<string, mixed>  $sourceContract
     * @param  array<string, mixed>  $calculationContract
     * @param  array<string, mixed>|null  $amountContract
     */
    public function hash(
        string $reportKey,
        string $definitionVersion,
        array $sourceContract,
        array $calculationContract,
        ?array $amountContract = null,
    ): string {
        $payload = [
            'report_key' => $reportKey,
            'definition_version' => $definitionVersion,
            'source_contract' => $this->normalise($sourceContract),
            'calculation_contract' => $this->normalise($calculationContract),
        ];

        // Amount facts are deliberately optional: AP/AR definitions already
        // have an approved calculation contract and must not be invalidated by
        // introducing the stock-specific D-04 contract. Omitting this key
        // also preserves hashes produced before amount_contract existed.
        if ($amountContract !== null) {
            $payload['amount_contract'] = $this->normalise($amountContract);
        }

        try {
            $payload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new \LogicException('Management report contracts must be JSON-serializable.', previous: $exception);
        }

        return hash('sha256', $payload);
    }

    /**
     * Compare JSON contract objects by their canonical structure, rather than
     * PHP insertion order. This is used when an adapter supplies its approved
     * exact representation contract to the executable-definition boundary.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    public function equivalent(array $left, array $right): bool
    {
        try {
            return json_encode($this->normalise($left), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
                === json_encode($this->normalise($right), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new \LogicException('Management report contracts must be JSON-serializable.', previous: $exception);
        }
    }

    private function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalised = [];
        foreach ($value as $key => $item) {
            $normalised[$key] = $this->normalise($item);
        }

        if (! array_is_list($normalised)) {
            ksort($normalised, SORT_STRING);
        }

        return $normalised;
    }
}
