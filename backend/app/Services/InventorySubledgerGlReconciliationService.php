<?php

namespace App\Services;

use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\AccountingPolicyVersion;
use App\Models\InventoryMovementEvent;
use App\Models\InventoryValuationRun;
use App\Models\InventorySubledgerGlReconciliationException;
use App\Models\InventorySubledgerGlReconciliationRun;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Captures immutable evidence of an inventory subledger-to-GL reconciliation.
 * Without an approved reducer contract it remains capability-only; with one,
 * the server computes ending inventory value from the canonical physical
 * movement sources and compares it to explicitly mapped GL accounts.
 *
 * The existing movement adapter is only used to validate its explicitly
 * declared one-day source contract. Its result is not summed: that adapter
 * neither proves a complete opening population nor a valuation basis.
 */
final class InventorySubledgerGlReconciliationService
{
    public const ALGORITHM_VERSION = 'inventory-subledger-gl-capability.v1';
    public const CONTROLLED_ALGORITHM_VERSION = 'inventory-subledger-gl-controlled.v1';
    private const POLICY_KEY = 'reconciliation.inventory';

    public function __construct(private readonly InventoryMovementV2Adapter $movements) {}

    public function capture(User $actor, string $asOfDate): InventorySubledgerGlReconciliationRun
    {
        $companyId = (int) $actor->company_id;
        if ($companyId < 1) throw new AuthorizationException('An actor company is required.');
        try { $cutoff = CarbonImmutable::parse($asOfDate)->toDateString(); } catch (\Throwable) { throw new InvalidArgumentException('As-of date is invalid.'); }

        $policy = $this->approvedPolicy($companyId, $cutoff);
        $contract = $this->reconciliationContract($policy);
        $adapterObservation = $this->adapterObservation($companyId, $cutoff);
        $lineage = $this->sourceJournalLineage($companyId, $cutoff);
        $completeness = $this->sourceCompleteness($adapterObservation, $lineage, $contract !== null, $this->controlMappingAvailable($companyId, $contract));
        $missing = array_values(array_filter($completeness, static fn (array $item): bool => ! $item['available']));
        return $this->captureInConsistentSnapshot($actor, $companyId, $cutoff, $completeness, $missing, $policy, $contract);
    }

    /**
     * Calculate controlled evidence and persist it inside one database
     * snapshot. The watermark is recorded in the immutable snapshot so a
     * later audit can prove which source rows were eligible for the reducer.
     */
    private function captureInConsistentSnapshot(
        User $actor,
        int $companyId,
        string $cutoff,
        array $completeness,
        array $missing,
        ?AccountingPolicyVersion $policy,
        ?array $contract,
    ): InventorySubledgerGlReconciliationRun {
        $driver = DB::connection()->getDriverName();
        $canSnapshot = $contract !== null && $missing === [] && in_array($driver, ['sqlite', 'mysql', 'mariadb'], true);
        if ($canSnapshot && in_array($driver, ['mysql', 'mariadb'], true) && DB::transactionLevel() > 0) {
            $canSnapshot = false;
        }
        if ($canSnapshot && in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction(function () use ($actor, $companyId, $cutoff, $completeness, $missing, $policy, $contract, $canSnapshot): InventorySubledgerGlReconciliationRun {
            $inputCutoffAt = now('UTC')->toImmutable();
            $controlled = null;
            $snapshotMissing = $missing;
            if ($contract !== null && $snapshotMissing === [] && $canSnapshot) {
                $controlled = $this->calculateControlled($companyId, $cutoff, $inputCutoffAt, $contract, $policy);
                $snapshotMissing = array_merge($snapshotMissing, $controlled['exceptions']);
            }
            $isControlled = $controlled !== null;
            $status = $isControlled ? ($snapshotMissing === [] ? 'approved' : 'blocked') : 'not_available';
            $snapshot = $isControlled ? $controlled['snapshot'] : [
                'schema' => self::ALGORITHM_VERSION,
                'as_of_date' => $cutoff,
                'status' => 'not_available',
                'source_completeness' => $completeness,
                // Never manufacture a zero inventory, COGS, GL balance or variance.
                'amounts' => null,
                'statement' => 'No inventory quantity/value, COGS, GL balance, or difference has been calculated by this foundation.',
            ];
            if ($isControlled) $snapshot['status'] = $status === 'approved' ? 'reconciled' : 'blocked';
            $contractHash = $this->hash([
                'schema' => $isControlled ? self::CONTROLLED_ALGORITHM_VERSION : self::ALGORITHM_VERSION,
                'adapter_source_contract' => InventoryMovementV2Adapter::supportedSourceContract(),
                'adapter_amount_contract' => InventoryMovementV2Adapter::supportedAmountContract(),
                'required_owner_contracts' => [
                    'complete_opening_and_cutoff_coverage', 'approved_valuation_and_cogs_policy',
                    'effective_dated_inventory_control_account_mapping', 'physical_count_exception_signoff_policy',
                ],
                'journal_lineage_rules' => ['posted_source', 'tenant_bound', 'current_source_journal_identity'],
                'policy_id' => $policy?->id, 'policy_contract_hash' => $policy?->contract_hash,
            ]);

            $run = InventorySubledgerGlReconciliationRun::withoutGlobalScope('company')->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'as_of_date' => $cutoff,
                'status' => $status, 'algorithm_version' => $isControlled ? self::CONTROLLED_ALGORITHM_VERSION : self::ALGORITHM_VERSION, 'contract_hash' => $contractHash,
                'source_completeness' => $completeness, 'divergence_count' => count($snapshotMissing), 'snapshot' => $snapshot,
                'snapshot_hash' => $this->hash($snapshot), 'requested_by' => $actor->id, 'recorded_at' => now(),
            ]);
            foreach ($snapshotMissing as $condition) {
                InventorySubledgerGlReconciliationException::withoutGlobalScope('company')->create([
                    'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'reconciliation_run_id' => $run->id,
                    'exception_code' => $condition['code'], 'severity' => 'blocking', 'reason' => $condition['reason'],
                    'evidence' => ['as_of_date' => $cutoff, 'contract_hash' => $contractHash, 'observed' => $condition['observed']], 'recorded_at' => now(),
                ]);
            }
            app(AuditService::class)->record($run, 'inventory_subledger_gl_reconciliation.captured', [], [
                'as_of_date' => $cutoff, 'status' => $status, 'blocking_conditions' => array_column($snapshotMissing, 'code'),
            ], metadata: ['algorithm_version' => $isControlled ? self::CONTROLLED_ALGORITHM_VERSION : self::ALGORITHM_VERSION, 'side_effects' => 'none', 'amounts_calculated' => (bool) ($snapshot['amounts_calculated'] ?? false), 'close_authority' => (bool) ($snapshot['close_authority'] ?? false)]);
            return $run->load('exceptions');
        }, 3);
    }

    private function approvedPolicy(int $companyId, string $cutoff): ?AccountingPolicyVersion
    {
        $policies = AccountingPolicyVersion::withoutGlobalScope('company')
            ->where('company_id', $companyId)->where('policy_key', self::POLICY_KEY)
            ->where('status', 'approved')->whereNotNull('approved_at')
            ->whereDate('effective_from', '<=', $cutoff)->whereDate('effective_to', '>=', $cutoff)
            ->orderByDesc('effective_from')->orderByDesc('id')->get();

        return $policies->count() === 1 ? $policies->first() : null;
    }

    /** @return array<string,mixed>|null */
    private function reconciliationContract(?AccountingPolicyVersion $policy): ?array
    {
        $policyContract = $policy?->posting_rule_contract;
        $contract = is_array($policyContract) ? ($policyContract['inventory_subledger_gl_reconciliation'] ?? null) : null;
        if (! is_array($contract)
            || ($contract['schema'] ?? null) !== 'inventory-subledger-gl-reconciliation.v1'
            || ($contract['algorithm'] ?? null) !== 'inventory-receipt-issue-transfer-v1'
            || ($contract['valuation_method'] ?? null) !== 'weighted_average'
            || ! is_string($contract['functional_currency'] ?? null)
            || ! is_array($contract['control_account_codes'] ?? null)
            || ($contract['control_account_codes'] ?? []) === []
            || ! in_array($contract['normal_balance'] ?? null, ['debit', 'credit'], true)
            || ! is_bool($contract['physical_count_signoff_required'] ?? null)) {
            return null;
        }
        $accounts = array_values(array_unique(array_map(static fn ($code): string => trim((string) $code), $contract['control_account_codes'])));
        if (in_array('', $accounts, true)) return null;
        $contract['control_account_codes'] = $accounts;
        $contract['functional_currency'] = strtoupper(trim((string) $contract['functional_currency']));
        return $contract;
    }

    /** @return array{snapshot:array<string,mixed>,exceptions:list<array<string,mixed>>} */
    private function calculateControlled(int $companyId, string $cutoff, CarbonImmutable $inputCutoffAt, array $contract, ?AccountingPolicyVersion $policy): array
    {
        $exceptions = [];
        $endingValue = DecimalMoney::ZERO;
        $endingQuantity = '0.0000';
        $receiptCount = 0;
        $issueCount = 0;
        $transferCount = 0;
        $rollforward = [];

        $opening = $this->openingInventory($companyId, $cutoff, $inputCutoffAt);
        $endingValue = DecimalMoney::add($endingValue, $opening['value']);
        $endingQuantity = $this->quantityAdd($endingQuantity, $opening['quantity']);
        foreach ($opening['rows'] as $row) {
            $warehouseId = $row->warehouse_id;
            if (! $this->validWarehouseLineage($companyId, $row->item_id, $warehouseId)) {
                $exceptions[] = $this->exception('inventory_opening_lineage_invalid', 'Confirmed opening inventory line is missing a same-tenant item or warehouse lineage.', ['opening_balance_inventory_lines', 'items', 'warehouses'], ['line_id' => (int) $row->id]);
                continue;
            }
            $this->addRollforward($rollforward, $row->item_id, $warehouseId, 'opening', $this->decimalMoney($row->total_value), (string) $row->quantity);
        }

        $receiptLines = DB::table('inventory_receipt_lines as lines')->join('inventory_receipts as headers', 'headers.id', '=', 'lines.inventory_receipt_id')
            ->where('headers.company_id', $companyId)->where('headers.is_posted', true)->whereDate('headers.voucher_date', '<=', $cutoff)
            ->where('headers.updated_at', '<=', $inputCutoffAt)->where('lines.updated_at', '<=', $inputCutoffAt)
            ->select(['lines.id', 'lines.item_id', 'lines.warehouse_id as line_warehouse_id', 'headers.warehouse_id as header_warehouse_id', 'lines.quantity', 'lines.amount'])->orderBy('lines.id')->get();
        foreach ($receiptLines as $line) {
            if (! $this->validWarehouseLineage($companyId, $line->item_id, $line->line_warehouse_id ?? $line->header_warehouse_id)) {
                $exceptions[] = $this->exception('inventory_movement_lineage_invalid', 'Posted receipt line is missing a same-tenant item or warehouse lineage.', ['inventory_receipt_lines', 'items', 'warehouses'], ['line_id' => (int) $line->id]);
                continue;
            }
            $endingValue = DecimalMoney::add($endingValue, $this->integerMoney($line->amount));
            $endingQuantity = $this->quantityAdd($endingQuantity, (string) $line->quantity);
            $this->addRollforward($rollforward, $line->item_id, $line->line_warehouse_id ?? $line->header_warehouse_id, 'receipt', $this->integerMoney($line->amount), (string) $line->quantity);
            $receiptCount++;
        }

        $issueLines = DB::table('inventory_issue_lines as lines')->join('inventory_issues as headers', 'headers.id', '=', 'lines.inventory_issue_id')
            ->where('headers.company_id', $companyId)->where('headers.is_posted', true)->whereDate('headers.voucher_date', '<=', $cutoff)
            ->where('headers.updated_at', '<=', $inputCutoffAt)->where('lines.updated_at', '<=', $inputCutoffAt)
            ->select(['lines.id', 'lines.item_id', 'lines.warehouse_id as line_warehouse_id', 'headers.warehouse_id as header_warehouse_id', 'lines.quantity', 'lines.amount'])->orderBy('lines.id')->get();
        foreach ($issueLines as $line) {
            if (! $this->validWarehouseLineage($companyId, $line->item_id, $line->line_warehouse_id ?? $line->header_warehouse_id)) {
                $exceptions[] = $this->exception('inventory_movement_lineage_invalid', 'Posted issue line is missing a same-tenant item or warehouse lineage.', ['inventory_issue_lines', 'items', 'warehouses'], ['line_id' => (int) $line->id]);
                continue;
            }
            $endingValue = DecimalMoney::subtract($endingValue, $this->integerMoney($line->amount));
            $endingQuantity = $this->quantityAdd($endingQuantity, $this->quantityNegate((string) $line->quantity));
            $this->addRollforward($rollforward, $line->item_id, $line->line_warehouse_id ?? $line->header_warehouse_id, 'issue', $this->integerMoney($line->amount), (string) $line->quantity);
            $issueCount++;
        }

        $events = InventoryMovementEvent::query()->where('company_id', $companyId)->whereDate('movement_date', '<=', $cutoff)->where('updated_at', '<=', $inputCutoffAt)->orderBy('id')->get(['id', 'item_id', 'warehouse_id', 'quantity_delta', 'amount_delta']);
        foreach ($events as $event) {
            if (! $this->validWarehouseLineage($companyId, $event->item_id, $event->warehouse_id)) {
                $exceptions[] = $this->exception('inventory_movement_lineage_invalid', 'Inventory transfer event is not tenant-bound.', ['inventory_movement_events', 'items', 'warehouses'], ['event_id' => (int) $event->id]);
                continue;
            }
            if ($event->amount_delta === null) {
                $exceptions[] = $this->exception('inventory_transfer_value_unavailable', 'A transfer movement has no carrying value; run weighted-average valuation before reconciliation.', ['inventory_movement_events', 'inventory_valuation_runs'], ['event_id' => (int) $event->id]);
                continue;
            }
            $endingValue = DecimalMoney::add($endingValue, $this->decimalMoney($event->amount_delta));
            $endingQuantity = $this->quantityAdd($endingQuantity, (string) $event->quantity_delta);
            $this->addRollforward($rollforward, $event->item_id, $event->warehouse_id, 'transfer', $this->decimalMoney($event->amount_delta), (string) $event->quantity_delta);
            $transferCount++;
        }
        $rollforwardRows = array_values($rollforward);
        usort($rollforwardRows, static fn (array $left, array $right): int => [$left['item_id'], $left['warehouse_id']] <=> [$right['item_id'], $right['warehouse_id']]);
        foreach ($rollforwardRows as &$row) {
            $row['ending_value'] = DecimalMoney::subtract(DecimalMoney::add($row['opening_value'], $row['receipt_value']), $row['issue_value']);
            $row['ending_value'] = DecimalMoney::add($row['ending_value'], $row['transfer_value']);
            $row['ending_quantity'] = $this->quantityAdd($row['opening_quantity'], $row['receipt_quantity']);
            $row['ending_quantity'] = $this->quantityAdd($row['ending_quantity'], $this->quantityNegate($row['issue_quantity']));
            $row['ending_quantity'] = $this->quantityAdd($row['ending_quantity'], $row['transfer_quantity']);
            if ($this->quantityCompare($row['ending_quantity'], '0') < 0) {
                $exceptions[] = $this->exception('inventory_negative_on_hand_at_cutoff', 'Inventory quantity is negative for an item and warehouse at the reconciliation cutoff.', ['inventory_receipt_lines', 'inventory_issue_lines', 'inventory_movement_events'], ['item_id' => $row['item_id'], 'warehouse_id' => $row['warehouse_id'], 'ending_quantity' => $row['ending_quantity']]);
            }
            if (DecimalMoney::compare($row['ending_value'], DecimalMoney::ZERO) < 0) {
                $exceptions[] = $this->exception('inventory_negative_value_at_cutoff', 'Inventory value is negative for an item and warehouse at the reconciliation cutoff.', ['inventory_receipt_lines', 'inventory_issue_lines', 'inventory_movement_events'], ['item_id' => $row['item_id'], 'warehouse_id' => $row['warehouse_id'], 'ending_value' => $row['ending_value']]);
            }
        }
        unset($row);
        if ($this->quantityCompare($endingQuantity, '0') < 0) {
            $exceptions[] = $this->exception('inventory_negative_on_hand_at_cutoff', 'Inventory quantity is negative at the reconciliation cutoff.', ['inventory_receipt_lines', 'inventory_issue_lines', 'inventory_movement_events'], ['ending_quantity' => $endingQuantity]);
        }

        $gl = DecimalMoney::ZERO;
        $glLineCount = 0;
        $rows = DB::table('journal_entry_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('entries.company_id', $companyId)->where('entries.status', 'posted')->whereDate('entries.posting_date', '<=', $cutoff)
            ->where('entries.updated_at', '<=', $inputCutoffAt)->where('lines.updated_at', '<=', $inputCutoffAt)
            ->whereIn('lines.account_code', $contract['control_account_codes'])->orderBy('lines.id')->get(['lines.debit_amount', 'lines.credit_amount']);
        foreach ($rows as $line) {
            $debit = $this->decimalMoney($line->debit_amount);
            $credit = $this->decimalMoney($line->credit_amount);
            $gl = $contract['normal_balance'] === 'debit'
                ? DecimalMoney::add($gl, DecimalMoney::subtract($debit, $credit))
                : DecimalMoney::add($gl, DecimalMoney::subtract($credit, $debit));
            $glLineCount++;
        }
        $openingGl = $this->openingGl($companyId, $cutoff, $inputCutoffAt, $contract);
        $gl = DecimalMoney::add($gl, $openingGl);
        $difference = DecimalMoney::subtract($endingValue, $gl);
        if (DecimalMoney::compare($difference, DecimalMoney::ZERO) !== 0) {
            $exceptions[] = $this->exception('inventory_subledger_gl_tie_out_mismatch', 'Inventory subledger value does not tie to the approved inventory control account at cutoff.', ['inventory_receipt_lines', 'inventory_issue_lines', 'inventory_movement_events', 'journal_entry_lines'], ['subledger_ending_value' => $endingValue, 'gl_inventory_balance' => $gl, 'difference' => $difference]);
        }
        $approvedAt = $policy?->approved_at?->toISOString();
        return ['snapshot' => [
            'schema' => self::CONTROLLED_ALGORITHM_VERSION, 'as_of_date' => $cutoff, 'status' => $exceptions === [] ? 'reconciled' : 'blocked',
            'amounts' => ['subledger_ending_value' => $endingValue, 'gl_inventory_balance' => $gl, 'difference' => $difference, 'ending_quantity' => $endingQuantity, 'receipt_line_count' => $receiptCount, 'issue_line_count' => $issueCount, 'transfer_event_count' => $transferCount, 'gl_control_line_count' => $glLineCount, 'opening_subledger_value' => $opening['value'], 'opening_gl_value' => $openingGl, 'item_warehouse_rollforward' => $rollforwardRows],
            'input_cutoff_at' => $inputCutoffAt->toISOString(),
            'amounts_calculated' => true, 'tie_out_calculated' => true, 'close_authority' => $exceptions === [],
            'control' => ['mode' => 'controlled', 'policy_id' => $policy?->id, 'policy_contract_hash' => $policy?->contract_hash, 'approved_at' => $approvedAt, 'eligible_for_period_close' => $exceptions === []],
            'statement' => $exceptions === [] ? 'Controlled inventory subledger-to-GL tie-out computed from confirmed opening stock, posted receipt/issue lines and valued transfer events.' : 'Controlled inventory reducer found blocking exceptions; no close authority is granted.',
        ], 'exceptions' => $exceptions];
    }

    /** @return array{value:string,quantity:string,rows:list<object>} */
    private function openingInventory(int $companyId, string $cutoff, CarbonImmutable $inputCutoffAt): array
    {
        if (! Schema::hasTable('opening_balance_packages')) return ['value' => DecimalMoney::ZERO, 'quantity' => '0.0000', 'rows' => []];
        $rows = DB::table('opening_balance_inventory_lines as lines')->join('opening_balance_packages as packages', 'packages.id', '=', 'lines.package_id')->where('packages.company_id', $companyId)->where('packages.status', 'confirmed')->whereDate('packages.effective_date', '<=', $cutoff)->where('packages.updated_at', '<=', $inputCutoffAt)->where('lines.updated_at', '<=', $inputCutoffAt)->get(['lines.id', 'lines.item_id', 'lines.warehouse_id', 'lines.total_value', 'lines.quantity']);
        $value = DecimalMoney::ZERO; $quantity = '0.0000';
        foreach ($rows as $row) { $value = DecimalMoney::add($value, $this->decimalMoney($row->total_value)); $quantity = $this->quantityAdd($quantity, (string) $row->quantity); }
        return ['value' => $value, 'quantity' => $quantity, 'rows' => $rows->all()];
    }

    private function openingGl(int $companyId, string $cutoff, CarbonImmutable $inputCutoffAt, array $contract): string
    {
        if (! Schema::hasTable('opening_balance_packages')) return DecimalMoney::ZERO;
        $package = DB::table('opening_balance_packages')->where('company_id', $companyId)->where('status', 'confirmed')->whereDate('effective_date', '<=', $cutoff)->where('updated_at', '<=', $inputCutoffAt)->latest('effective_date')->latest('id')->first();
        if ($package === null) return DecimalMoney::ZERO;
        $value = DecimalMoney::ZERO;
        foreach (DB::table('opening_balance_account_lines')->where('package_id', $package->id)->where('updated_at', '<=', $inputCutoffAt)->whereIn('account_code', $contract['control_account_codes'])->get(['debit_amount', 'credit_amount']) as $line) {
            $delta = $contract['normal_balance'] === 'debit' ? DecimalMoney::subtract($this->decimalMoney($line->debit_amount), $this->decimalMoney($line->credit_amount)) : DecimalMoney::subtract($this->decimalMoney($line->credit_amount), $this->decimalMoney($line->debit_amount));
            $value = DecimalMoney::add($value, $delta);
        }
        return $value;
    }

    private function validWarehouseLineage(int $companyId, mixed $itemId, mixed $warehouseId): bool
    {
        return $itemId !== null && $warehouseId !== null
            && DB::table('items')->where('id', $itemId)->where('company_id', $companyId)->exists()
            && DB::table('warehouses')->where('id', $warehouseId)->where('company_id', $companyId)->exists();
    }

    private function integerMoney(mixed $value): string
    {
        $text = trim((string) ($value ?? '0'));
        return preg_match('/^[+-]?\d+$/', $text) ? DecimalMoney::normalize($text.'.00') : $this->decimalMoney($value);
    }

    private function decimalMoney(mixed $value): string
    {
        try { return DecimalMoney::normalize((string) ($value ?? '0')); } catch (\Throwable) { return DecimalMoney::ZERO; }
    }

    private function quantityAdd(string $left, string $right): string
    {
        try { return \Brick\Math\BigDecimal::of($left)->plus(\Brick\Math\BigDecimal::of($right))->toScale(4)->__toString(); } catch (\Throwable) { return $left; }
    }

    private function quantityNegate(string $value): string
    {
        try { return \Brick\Math\BigDecimal::of($value)->negated()->toScale(4)->__toString(); } catch (\Throwable) { return '0.0000'; }
    }

    private function quantityCompare(string $left, string $right): int
    {
        return \Brick\Math\BigDecimal::of($left)->compareTo(\Brick\Math\BigDecimal::of($right));
    }

    /** @param array<string,array<string,mixed>> $rollforward */
    private function addRollforward(array &$rollforward, mixed $itemId, mixed $warehouseId, string $kind, string $value, string $quantity): void
    {
        if ($itemId === null || $warehouseId === null) return;
        $key = (string) $itemId.':'.(string) $warehouseId;
        if (! isset($rollforward[$key])) {
            $rollforward[$key] = [
                'item_id' => (int) $itemId,
                'warehouse_id' => (int) $warehouseId,
                'opening_value' => DecimalMoney::ZERO,
                'opening_quantity' => '0.0000',
                'receipt_value' => DecimalMoney::ZERO,
                'receipt_quantity' => '0.0000',
                'issue_value' => DecimalMoney::ZERO,
                'issue_quantity' => '0.0000',
                'transfer_value' => DecimalMoney::ZERO,
                'transfer_quantity' => '0.0000',
            ];
        }
        $valueKey = $kind.'_value';
        $quantityKey = $kind.'_quantity';
        if (! array_key_exists($valueKey, $rollforward[$key]) || ! array_key_exists($quantityKey, $rollforward[$key])) return;
        $rollforward[$key][$valueKey] = DecimalMoney::add((string) $rollforward[$key][$valueKey], $value);
        $rollforward[$key][$quantityKey] = $this->quantityAdd((string) $rollforward[$key][$quantityKey], $quantity);
    }

    /** @return array{code:string,reason:string,required_tables:list<string>,observed:array<string,mixed>} */
    private function exception(string $code, string $reason, array $requiredTables, array $observed): array
    {
        return ['code' => $code, 'reason' => $reason, 'required_tables' => $requiredTables, 'observed' => $observed];
    }

    private function controlMappingAvailable(int $companyId, ?array $contract): bool
    {
        if ($contract === null || ! Schema::hasTable('chart_of_accounts')) return false;
        $codes = $contract['control_account_codes'] ?? [];
        $codes = is_array($codes) ? array_values(array_unique(array_filter(array_map(static fn ($code): string => trim((string) $code), $codes)))) : [];
        if ($codes === []) return false;
        return DB::table('chart_of_accounts')->where('company_id', $companyId)->where('is_active', true)->whereIn('code', $codes)->count() === count($codes);
    }

    /** @return array{available:bool,observed:array<string,mixed>} */
    private function adapterObservation(int $companyId, string $cutoff): array
    {
        try {
            // A one-day range is observation only. It makes no claim about an
            // opening balance or population before the cutoff.
            $rows = $this->movements->movements($companyId, $cutoff, $cutoff,
                InventoryMovementV2Adapter::supportedSourceContract(),
                InventoryMovementV2Adapter::supportedAmountContract());
            return ['available' => true, 'observed' => ['cutoff_day_movement_count' => count($rows), 'error' => null]];
        } catch (\Throwable $exception) {
            return ['available' => false, 'observed' => ['cutoff_day_movement_count' => null, 'error' => $exception->getMessage()]];
        }
    }

    /** @return array{posted_source_count:int,provable_journal_count:int,unproven_count:int,invalid_source_ids:list<string>} */
    private function sourceJournalLineage(int $companyId, string $cutoff): array
    {
        $sources = [];
        foreach ([[InventoryReceipt::class, 'inventory_receipt'], [InventoryIssue::class, 'inventory_issue']] as [$class, $type]) {
            $rows = $class::withoutGlobalScope('company')->where('company_id', $companyId)->where('is_posted', true)
                ->whereDate('voucher_date', '<=', $cutoff)->get(['id', 'journal_entry_id']);
            foreach ($rows as $row) $sources[] = ['class' => $class, 'type' => $type, 'id' => (int) $row->id, 'journal_entry_id' => $row->journal_entry_id];
        }
        $valid = 0; $invalid = [];
        foreach ($sources as $source) {
            $exists = $source['journal_entry_id'] !== null && JournalEntry::withoutGlobalScope('company')
                ->where('company_id', $companyId)->whereKey($source['journal_entry_id'])->where('status', 'posted')
                ->where('source_document_type', $source['class'])->where('source_document_id', $source['id'])->exists();
            if ($exists) $valid++; else $invalid[] = $source['type'].':'.$source['id'];
        }
        return ['posted_source_count' => count($sources), 'provable_journal_count' => $valid, 'unproven_count' => count($invalid), 'invalid_source_ids' => $invalid];
    }

    /** @return array<string,array{available:bool,code:string,reason:string,observed:array<string,mixed>}> */
    private function sourceCompleteness(array $adapterObservation, array $lineage, bool $hasApprovedContract, bool $mappingAvailable): array
    {
        $schemaAvailable = Schema::hasTable('inventory_receipts')
            && Schema::hasColumns('inventory_receipts', ['company_id', 'id', 'is_posted', 'voucher_date', 'journal_entry_id'])
            && Schema::hasTable('inventory_receipt_lines') && Schema::hasColumns('inventory_receipt_lines', ['inventory_receipt_id', 'item_id', 'warehouse_id', 'quantity', 'amount'])
            && Schema::hasTable('inventory_issues')
            && Schema::hasColumns('inventory_issues', ['company_id', 'id', 'is_posted', 'voucher_date', 'journal_entry_id'])
            && Schema::hasTable('inventory_issue_lines') && Schema::hasColumns('inventory_issue_lines', ['inventory_issue_id', 'item_id', 'warehouse_id', 'quantity', 'amount'])
            && Schema::hasTable('journal_entries') && Schema::hasColumns('journal_entries', ['company_id', 'id', 'status', 'source_document_type', 'source_document_id']);
        return [
            'inventory_movement_source_schema' => $this->condition($schemaAvailable, 'inventory_gl_source_schema_unavailable', 'The inventory receipt/issue, line, or posted-journal source-lineage schema is unavailable.', ['schema_available' => $schemaAvailable]),
            'movement_adapter_contract' => $this->condition($adapterObservation['available'], 'inventory_movement_adapter_contract_unproven', 'The declared InventoryMovementV2Adapter source contract could not read the cutoff-day population without error.', $adapterObservation['observed']),
            'posted_source_journal_lineage' => $this->condition($lineage['unproven_count'] === 0, 'inventory_source_journal_lineage_unproven', 'At least one posted inventory receipt/issue up to cutoff lacks a matching posted source journal entry.', $lineage),
            'opening_and_cutoff_coverage' => $this->condition($hasApprovedContract && Schema::hasTable('opening_balance_packages') && Schema::hasTable('opening_balance_inventory_lines'), 'inventory_opening_cutoff_coverage_not_owner_approved', 'No owner-approved opening stock population, migration, overlap/gap and cutoff-coverage contract proves the full inventory subledger scope.', ['adapter_is_not_a_balance_engine' => true]),
            'valuation_and_cogs_policy' => $this->condition($hasApprovedContract, 'inventory_valuation_cogs_policy_not_owner_approved', 'No owner-approved effective-dated valuation method, cost-layer, rounding, negative-stock, write-down, and COGS treatment proves a value reducer.', ['amounts_calculated' => false]),
            'inventory_control_account_mapping' => $this->condition($mappingAvailable, 'inventory_control_account_mapping_not_owner_approved', $hasApprovedContract ? 'The approved inventory control-account mapping is missing an active tenant account.' : 'No effective-dated owner-approved mapping proves which GL accounts and lines represent inventory and COGS for this reconciliation.', ['gl_amounts_calculated' => false]),
            'physical_count_exception_signoff_policy' => $this->condition($hasApprovedContract, 'inventory_physical_count_exception_policy_not_owner_approved', 'No owner-approved physical-count cutoff, adjustment, exception, waiver and SoD sign-off contract exists.', ['close_authority' => false]),
        ];
    }

    /** @param array<string,mixed> $observed @return array{available:bool,code:string,reason:string,observed:array<string,mixed>} */
    private function condition(bool $available, string $code, string $reason, array $observed): array { return compact('available', 'code', 'reason', 'observed'); }
    private function hash(array $payload): string { $this->sort($payload); return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
    private function sort(array &$value): void { foreach ($value as &$item) if (is_array($item)) $this->sort($item); unset($item); if (! array_is_list($value)) ksort($value); }
}
