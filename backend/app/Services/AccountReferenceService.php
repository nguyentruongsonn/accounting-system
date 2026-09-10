<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** One tenant-aware reference inventory for account deletion and transfer. */
final class AccountReferenceService
{
    private const REFERENCES = [
        'journal_entry_lines' => ['account_code'],
        'cash_receipt_lines' => ['debit_account', 'credit_account'],
        'cash_payment_lines' => ['debit_account', 'credit_account'],
        'bank_receipt_lines' => ['debit_account', 'credit_account'],
        'bank_payment_lines' => ['debit_account', 'credit_account'],
        'purchase_invoice_lines' => ['debit_account', 'credit_account', 'tax_account'],
        'sales_invoice_lines' => ['debit_account', 'credit_account', 'tax_account', 'inventory_account', 'cogs_account', 'cogs_debit_account', 'cogs_credit_account'],
        'purchase_return_lines' => ['debit_account', 'credit_account', 'tax_account'],
        'purchase_discount_lines' => ['debit_account', 'credit_account', 'tax_account'],
        'sales_return_lines' => ['debit_account', 'credit_account', 'tax_account', 'inventory_account', 'cogs_account', 'cogs_debit_account', 'cogs_credit_account'],
        'sales_discount_lines' => ['debit_account', 'credit_account', 'tax_account'],
        'inventory_receipt_lines' => ['debit_account', 'credit_account'],
        'inventory_issue_lines' => ['debit_account', 'credit_account'],
        'payroll_lines' => ['debit_account', 'credit_account'],
        'customers' => ['default_account'], 'suppliers' => ['default_account'],
        'items' => ['inventory_account', 'revenue_account', 'discount_account'],
        'warehouses' => ['default_account'],
        'voucher_type_settings' => ['debit_account', 'credit_account'],
        'closing_rules' => ['debit_account', 'credit_account'],
        'approved_account_mappings' => ['account_code'], 'budgets' => ['account_code'],
        'cash_inventories' => ['account_code'],
        'fixed_assets' => ['asset_account', 'depreciation_account', 'expense_account', 'credit_account'],
        'tool_equipments' => ['tool_account', 'expense_account'],
        'borrowing_contracts' => ['debit_account', 'interest_account'],
        'asset_depreciation_log_lines' => ['expense_account', 'depreciation_account'],
        'asset_disposals' => ['asset_account', 'depreciation_account', 'expense_account', 'income_account', 'receivable_account', 'tax_account'],
        'asset_revaluations' => ['asset_account', 'revaluation_account', 'depreciation_account'],
        'opening_balance_account_lines' => ['account_code'],
        'opening_balance_party_lines' => ['account_code'],
        'opening_balance_inventory_lines' => ['account_code'],
    ];

    public function __construct(private readonly AccountingPeriodGuard $periodGuard) {}

    /** @return array<string, int> Counts are reference cells, not document counts. */
    public function inspect(int $companyId, string $code, bool $forTransfer = false): array
    {
        $result = [];
        foreach (self::REFERENCES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $base = $this->tenantQuery($table, $companyId);
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $query = (clone $base)->where($column, $code);
                $rows = $query->lockForUpdate()->get();
                if ($rows->isEmpty()) {
                    continue;
                }
                $result[$table.'.'.$column] = $rows->count();
                if ($forTransfer) {
                    $this->assertMutable($table, $rows->all(), $companyId);
                }
            }
        }

        return $result;
    }

    public function transfer(int $companyId, string $from, string $to): int
    {
        $references = $this->inspect($companyId, $from, true);
        $affected = 0;
        foreach ($references as $reference => $count) {
            [$table, $column] = explode('.', $reference);
            $updates = [$column => $to];
            if (Schema::hasColumn($table, 'updated_at')) {
                $updates['updated_at'] = now();
            }
            $affected += $this->tenantQuery($table, $companyId)->where($column, $from)->update($updates);
        }

        return $affected;
    }

    private function tenantQuery(string $table, int $companyId): Builder
    {
        $query = DB::table($table);
        if (str_ends_with($table, '_lines')) {
            $stem = substr($table, 0, -6);
            [$parent, $foreignKey] = match ($table) {
                'journal_entry_lines' => ['journal_entries', 'journal_entry_id'],
                'asset_depreciation_log_lines' => ['asset_depreciation_logs', 'depreciation_log_id'],
                'opening_balance_account_lines',
                'opening_balance_party_lines',
                'opening_balance_inventory_lines' => ['opening_balance_packages', 'package_id'],
                default => [$stem.'s', $stem.'_id'],
            };
            if (! Schema::hasTable($parent) || ! Schema::hasColumn($table, $foreignKey)) {
                throw ValidationException::withMessages(['account' => 'Không xác định được chứng từ sở hữu tham chiếu tài khoản: '.$table]);
            }

            return $query->whereIn($foreignKey, DB::table($parent)->select('id')->where('company_id', $companyId));
        }
        if (! Schema::hasColumn($table, 'company_id')) {
            throw ValidationException::withMessages(['account' => 'Không xác định được công ty sở hữu tham chiếu tài khoản: '.$table]);
        }

        return $query->where('company_id', $companyId);
    }

    /** @param list<object> $rows */
    private function assertMutable(string $table, array $rows, int $companyId): void
    {
        if (in_array($table, [
            'approved_account_mappings', 'cash_inventories', 'fixed_assets',
            'tool_equipments', 'borrowing_contracts', 'asset_depreciation_log_lines',
            'asset_disposals', 'asset_revaluations',
        ], true)) {
            throw ValidationException::withMessages(['account' => 'Tài khoản đã được dùng trong cấu hình hoặc hồ sơ được lưu vết; không thể chuyển hàng loạt.']);
        }
        if (! str_ends_with($table, '_lines')) {
            return;
        }
        $stem = substr($table, 0, -6);
        [$parent, $foreignKey] = match ($table) {
            'journal_entry_lines' => ['journal_entries', 'journal_entry_id'],
            'asset_depreciation_log_lines' => ['asset_depreciation_logs', 'depreciation_log_id'],
            'opening_balance_account_lines',
            'opening_balance_party_lines',
            'opening_balance_inventory_lines' => ['opening_balance_packages', 'package_id'],
            default => [$stem.'s', $stem.'_id'],
        };
        $ids = array_unique(array_map(fn ($row) => $row->$foreignKey, $rows));
        $documents = DB::table($parent)->where('company_id', $companyId)->whereIn('id', $ids)->lockForUpdate()->get();
        foreach ($documents as $document) {
            $status = strtolower((string) ($document->status ?? ''));
            if (($document->is_posted ?? false) || ($document->deleted_at ?? null)
                || ($parent === 'journal_entries' && $status !== 'draft')
                || ($parent === 'opening_balance_packages' && $status !== 'draft')
                || in_array($status, ['posted', 'void', 'voided', 'cancelled', 'canceled'], true)) {
                throw ValidationException::withMessages(['account' => 'Tài khoản đã có lịch sử ghi sổ hoặc hủy; không được sửa tài khoản trong lịch sử.']);
            }
            $date = $document->posting_date ?? $document->voucher_date ?? $document->invoice_date ?? $document->return_date ?? $document->discount_date ?? $document->effective_date ?? null;
            if ($date === null) {
                throw ValidationException::withMessages(['account' => 'Không xác định được kỳ của chứng từ tham chiếu tài khoản.']);
            }
            $this->periodGuard->assertOpen($companyId, $date, 'chuyển tài khoản hạch toán');
        }
    }
}
