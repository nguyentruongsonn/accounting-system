<?php

namespace App\Services;

use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Illuminate\Validation\ValidationException;

/**
 * Read-only, evidence-preserving movement boundary for inventory reporting v2.
 *
 * This is intentionally not a stock balance or valuation engine.  It emits
 * only posted inventory receipt/issue lines, so a purchase invoice can never
 * accidentally create a second stock movement.  A report definition must pass
 * the explicit source and representation contracts below before this adapter
 * reads data. It is not an inventory valuation or reconciliation engine.
 */
final class InventoryMovementV2Adapter
{
    /** @var list<string> */
    private const SUPPORTED_SOURCES = ['inventory_receipt', 'inventory_issue'];

    /** @return array<string, mixed> */
    public static function supportedSourceContract(): array
    {
        return [
            'version' => 1,
            'sources' => self::SUPPORTED_SOURCES,
            'posting_status' => 'posted',
            'date_basis' => 'voucher_date',
            'purchase_invoices_create_movement' => false,
            'warehouse_lineage' => 'line_then_header',
        ];
    }

    /** @return array<string, mixed> */
    public static function supportedAmountContract(): array
    {
        return [
            'quantity' => ['source_representation' => 'integer', 'scale' => 0, 'output_representation' => 'integer_string'],
            'money' => ['source_representation' => 'integer', 'scale' => 0, 'output_representation' => 'integer_string'],
        ];
    }

    /** @return array<string, mixed> */
    public static function supportedCalculationContract(): array
    {
        return [
            'schema' => 'inventory-movement-source.v2',
            'result' => 'posted_receipt_issue_movements_only',
            'stock_balance_or_valuation' => false,
            'negative_movements' => 'preserve_signed',
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceContract
     * @param  array<string, mixed>  $amountContract
     * @param  array{item_id?: int, warehouse_id?: int}  $filters
     * @return list<array<string, int|string>>
     */
    public function movements(
        int $companyId,
        string $fromDate,
        string $toDate,
        array $sourceContract,
        array $amountContract,
        array $filters = [],
    ): array {
        $companyId = $this->requireCompanyId($companyId);
        $this->assertContracts($sourceContract, $amountContract);
        $this->assertFilters($filters);

        $from = $this->date($fromDate, 'fromDate');
        $to = $this->date($toDate, 'toDate');
        if ($from->gt($to)) {
            throw new InvalidArgumentException('Inventory movement range must be complete and fromDate must not be after toDate.');
        }

        $receipts = InventoryReceipt::query()
            ->where('company_id', $companyId)
            ->where('is_posted', true)
            ->whereDate('voucher_date', '>=', $from->toDateString())
            ->whereDate('voucher_date', '<=', $to->toDateString())
            ->with(['lines.item', 'lines.warehouse'])
            ->orderBy('voucher_date')
            ->orderBy('id')
            ->get();

        $issues = InventoryIssue::query()
            ->where('company_id', $companyId)
            ->where('is_posted', true)
            ->whereDate('voucher_date', '>=', $from->toDateString())
            ->whereDate('voucher_date', '<=', $to->toDateString())
            ->with(['lines.item', 'lines.warehouse'])
            ->orderBy('voucher_date')
            ->orderBy('id')
            ->get();

        $movements = [];
        foreach ($receipts as $receipt) {
            foreach ($receipt->lines as $line) {
                $movement = $this->movement($companyId, $receipt, $line, 'inventory_receipt', 1);
                if ($this->matches($movement, $filters)) {
                    $movements[] = $movement;
                }
            }
        }
        foreach ($issues as $issue) {
            foreach ($issue->lines as $line) {
                $movement = $this->movement($companyId, $issue, $line, 'inventory_issue', -1);
                if ($this->matches($movement, $filters)) {
                    $movements[] = $movement;
                }
            }
        }

        usort($movements, static fn (array $left, array $right): int => [
            $left['voucher_date'], $left['source_type'], $left['source_id'], $left['source_line_id'],
        ] <=> [
            $right['voucher_date'], $right['source_type'], $right['source_id'], $right['source_line_id'],
        ]);

        return $movements;
    }

    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId < 1 || ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId)) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }

    /** @param array<string, mixed> $sourceContract @param array<string, mixed> $amountContract */
    private function assertContracts(array $sourceContract, array $amountContract): void
    {
        $this->assertExactContract($sourceContract, self::supportedSourceContract(), 'Unsupported inventory movement source contract; adapter is fail-closed.');
        $this->assertExactContract($amountContract, self::supportedAmountContract(), 'Unsupported inventory movement quantity/money representation contract; adapter is fail-closed.');
    }

    /** @param array<string, mixed> $filters */
    private function assertFilters(array $filters): void
    {
        foreach ($filters as $key => $value) {
            if (! in_array($key, ['item_id', 'warehouse_id'], true) || ! is_int($value) || $value < 1) {
                throw new InvalidArgumentException('Inventory v2 filters support only positive integer item_id and warehouse_id.');
            }
        }
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException("{$field} must be an ISO Y-m-d calendar date.");
            }

            return $date;
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException("{$field} must be an ISO Y-m-d date.", previous: $exception);
        }
    }

    /** @return array<string, int|string> */
    private function movement(int $companyId, object $voucher, object $line, string $sourceType, int $direction): array
    {
        $item = $line->item;
        if ($item === null || (int) $item->company_id !== $companyId) {
            throw new InvalidArgumentException('Inventory movement item lineage is not tenant-bound; adapter is fail-closed.');
        }

        $warehouseId = $line->warehouse_id ?? $voucher->warehouse_id ?? null;
        if (! is_int($warehouseId) && ! ctype_digit((string) $warehouseId)) {
            throw new InvalidArgumentException('Inventory movement requires a warehouse lineage; adapter is fail-closed.');
        }
        $warehouse = $line->warehouse;
        if ($warehouse === null) {
            $warehouse = Warehouse::query()->find($warehouseId);
        }
        if ($warehouse === null || (int) $warehouse->id !== (int) $warehouseId || (int) $warehouse->company_id !== $companyId) {
            throw new InvalidArgumentException('Inventory movement warehouse lineage is not tenant-bound; adapter is fail-closed.');
        }

        // Source migrations use INTEGER/BIGINT; raw values prevent Eloquent
        // decimal casts from inventing a scale that the source never declared.
        $quantity = $this->exactInteger($line->getRawOriginal('quantity'), 'quantity');
        $amount = $this->exactInteger($line->getRawOriginal('amount'), 'amount');

        return [
            'source_type' => $sourceType,
            'source_id' => (int) $voucher->id,
            'source_line_id' => (int) $line->id,
            'voucher_number' => (string) $voucher->voucher_number,
            'voucher_date' => $voucher->voucher_date->toDateString(),
            'item_id' => (int) $item->id,
            'warehouse_id' => (int) $warehouseId,
            'quantity' => $direction > 0 ? $quantity : $this->negateInteger($quantity),
            'amount' => $direction > 0 ? $amount : $this->negateInteger($amount),
        ];
    }

    private function exactInteger(mixed $value, string $field): string
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException("Inventory movement {$field} must be an exact integer string or integer.");
        }

        $value = trim((string) $value);
        if (! preg_match('/^[+-]?\d+$/', $value)) {
            throw new InvalidArgumentException("Inventory movement {$field} does not satisfy the declared integer scale-0 source contract.");
        }

        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative || str_starts_with($value, '+') ? substr($value, 1) : $value, '0');
        $digits = $digits === '' ? '0' : $digits;

        return $negative && $digits !== '0' ? '-'.$digits : $digits;
    }

    private function negateInteger(string $value): string
    {
        return $value === '0' ? '0' : (str_starts_with($value, '-') ? substr($value, 1) : '-'.$value);
    }

    /** @param array<string, mixed> $actual @param array<string, mixed> $expected */
    private function assertExactContract(array $actual, array $expected, string $message): void
    {
        $actualKeys = array_keys($actual);
        $expectedKeys = array_keys($expected);
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException($message);
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || $actual[$key] !== $value) {
                throw new InvalidArgumentException($message);
            }
        }
    }

    /** @param array<string, int|string> $movement @param array<string, mixed> $filters */
    private function matches(array $movement, array $filters): bool
    {
        return (! isset($filters['item_id']) || $movement['item_id'] === $filters['item_id'])
            && (! isset($filters['warehouse_id']) || $movement['warehouse_id'] === $filters['warehouse_id']);
    }
}
