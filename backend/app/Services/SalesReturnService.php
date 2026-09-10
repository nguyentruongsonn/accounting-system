<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\JournalEntry;
use App\Models\SalesReturn;
use App\Services\Concerns\GuardsPostedDependentDocuments;
use App\Services\Concerns\GuardsPostedSettlementAllocations;
use App\Support\CommercialSourceAuditContext;
use App\Support\DecimalMoney;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SalesReturnService extends BaseService
{
    use GuardsPostedDependentDocuments, GuardsPostedSettlementAllocations;

    public function __construct(
        protected JournalEntryService $journalEntryService,
        private readonly AuditService $auditService,
        private readonly AccountingPolicyResolver $accountingPolicyResolver,
        private readonly CommercialAdjustmentAccountMappingPostingGate $accountMappingGate,
        private readonly CommercialAdjustmentSettlementService $adjustmentSettlements,
        private readonly AccountingPeriodGuard $periodGuard,
    ) {}

    public function getAll(array $filters = [])
    {
        $query = SalesReturn::withoutGlobalScope('company')
            ->where('company_id', $this->resolveCompanyId([]))
            ->with([
                'customer',
                'employee',
                'bankAccount',
                'lines.item',
                'lines.warehouse',
                'references',
                'journalEntry.lines',
            ])
            ->orderBy('voucher_date', 'desc')
            ->orderBy('id', 'desc');

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('voucher_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('voucher_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('voucher_number', 'like', "%{$s}%")
                    ->orWhere('customer_name', 'like', "%{$s}%")
                    ->orWhere('reason', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            });
        }

        if (isset($filters['per_page']) && is_numeric($filters['per_page']) && (int) $filters['per_page'] > 0) {
            return $query->paginate((int) $filters['per_page']);
        }

        return $query->get();
    }

    public function getById(int|string $id): SalesReturn
    {
        return SalesReturn::withoutGlobalScope('company')
            ->where('company_id', $this->resolveCompanyId([]))
            ->with([
                'lines.item',
                'lines.warehouse',
                'customer',
                'employee',
                'bankAccount',
                'referenceInvoice',
                'references',
                'journalEntry.lines',
            ])->findOrFail($id);
    }

    public function create(array $data): SalesReturn
    {
        $autoPost = ! empty($data['is_posted']) || ($data['status'] ?? '') === 'posted';
        if ($autoPost) {
            $this->assertCanAutoPost();
        }

        $data['company_id'] = $this->resolveCompanyId($data);
        $accountingDate = $data['accounting_date'] ?? $data['voucher_date'] ?? now()->toDateString();
        $this->periodGuard->assertOpen((int) $data['company_id'], $accountingDate, 'lập chứng từ trả lại hàng bán');

        return DB::transaction(function () use ($data, $autoPost) {
            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $discountAmount = $this->money($data['discount_amount'] ?? DecimalMoney::ZERO);
            $cogsTotalAmount = DecimalMoney::ZERO;
            $lines = $data['lines'] ?? [];
            $preparedLines = [];

            if (! is_array($lines) || $lines === []) {
                throw ValidationException::withMessages([
                    'lines' => 'A sales return requires at least one detail line.',
                ]);
            }
            if (empty($data['customer_id'])) {
                throw ValidationException::withMessages([
                    'customer_id' => 'A customer is required for a sales return.',
                ]);
            }

            foreach ($lines as $line) {
                $amounts = $this->lineAmounts($line);
                $preparedLines[] = array_merge($line, $amounts);
                $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
                $cogsTotalAmount = DecimalMoney::add($cogsTotalAmount, $amounts['cogs_amount']);
            }

            $isInward = isset($data['is_inward']) ? (bool) $data['is_inward'] : (isset($data['is_import_slip']) ? (bool) $data['is_import_slip'] : true);
            CommercialDraftAccountEvidenceGate::assertSatisfied('sales_return', $lines, [
                'inward' => $isInward,
            ]);

            $totalAmount = DecimalMoney::add(
                DecimalMoney::subtract($subTotal, $discountAmount),
                $taxAmount,
            );
            $paymentMethod = $data['payment_method'] ?? 'reduce_receivable';
            $isDecreaseDebt = isset($data['is_decrease_debt']) ? (bool) $data['is_decrease_debt'] : ($paymentMethod === 'reduce_receivable');

            $companyId = (int) $data['company_id'];
            $voucherNumber = $data['voucher_number'] ?? $this->generateNextCode($companyId);

            $salesReturn = SalesReturn::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'tax_code' => $data['tax_code'] ?? null,
                'receiver_name' => $data['receiver_name'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'voucher_type' => $data['voucher_type'] ?? 'sales_return',
                'payment_method' => $paymentMethod,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $data['voucher_date'] ?? now()->toDateString(),
                'accounting_date' => $data['accounting_date'] ?? $data['voucher_date'] ?? now()->toDateString(),
                'reason' => $data['reason'] ?? null,
                'description' => $data['description'] ?? $data['reason'] ?? null,
                'attached_docs' => $data['attached_docs'] ?? null,
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'grand_total' => $totalAmount,
                'cogs_total_amount' => $cogsTotalAmount,
                'is_posted' => false,
                'is_inward' => $isInward,
                'is_import_slip' => $isInward,
                'is_decrease_debt' => $isDecreaseDebt,
                'status' => 'draft',
                'reference_invoice_id' => $data['reference_invoice_id'] ?? null,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            $defaultCredit = match ($paymentMethod) {
                'cash' => '1111',
                'bank' => '1121',
                default => '131',
            };

            foreach ($preparedLines as $idx => $line) {
                $salesReturn->lines()->create([
                    'line_order' => $line['line_order'] ?? ($idx + 1),
                    'item_id' => $line['item_id'] ?? null,
                    'item_code' => $line['item_code'] ?? null,
                    'item_name' => $line['item_name'] ?? $line['description'] ?? null,
                    'description' => $line['description'] ?? $line['item_name'] ?? $salesReturn->reason ?? 'Trả lại hàng bán',
                    'unit' => $line['unit'] ?? null,
                    'warehouse_id' => $line['warehouse_id'] ?? null,
                    'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'sales_return', '5212'),
                    'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'sales_return', $defaultCredit),
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['tax'],
                    'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'sales_return', '33311'),
                    'inventory_account' => CommercialDraftAccountEvidenceGate::account($line, 'inventory_account', 'sales_return', $line['cogs_debit_account'] ?? '1561'),
                    'cogs_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_account', 'sales_return', $line['cogs_credit_account'] ?? '632'),
                    'cogs_debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_debit_account', 'sales_return', $line['inventory_account'] ?? '1561'),
                    'cogs_credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_credit_account', 'sales_return', $line['cogs_account'] ?? '632'),
                    'cogs_price' => $line['cogs_price'],
                    'cogs_unit_price' => $line['cogs_unit_price'],
                    'cogs_amount' => $line['cogs_amount'],
                    'invoice_number' => $line['invoice_number'] ?? null,
                    'invoice_date' => $line['invoice_date'] ?? null,
                    'sales_order_id' => $line['sales_order_id'] ?? null,
                    'contract_id' => $line['contract_id'] ?? null,
                ]);
            }

            if (! empty($data['referenced_vouchers'])) {
                $salesReturn->syncReferences($data['referenced_vouchers']);
            }

            if ($autoPost) {
                return $this->post($salesReturn->id);
            }

            return $salesReturn->load(['lines.item', 'lines.warehouse', 'customer', 'employee', 'bankAccount', 'references']);
        });
    }

    public function update(int|string $id, array $data): SalesReturn
    {
        $this->assertDraftMutationIntent($data);

        return DB::transaction(function () use ($id, $data) {
            $salesReturn = SalesReturn::withoutGlobalScope('company')
                ->where('company_id', $this->resolveCompanyId([]))
                ->with('lines')
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $salesReturn->company_id, $salesReturn->accounting_date ?? $salesReturn->voucher_date, 'sửa chứng từ trả lại hàng bán');
            if (array_key_exists('accounting_date', $data)) {
                $this->periodGuard->assertOpen((int) $salesReturn->company_id, $data['accounting_date'], 'sửa chứng từ trả lại hàng bán');
            }

            $this->assertMutableSource($salesReturn, 'sửa');

            $lines = $data['lines'] ?? null;
            CommercialDraftAccountEvidenceGate::assertUpdateLinesProvided('sales_return', $lines);
            $subTotal = DecimalMoney::ZERO;
            $taxAmount = DecimalMoney::ZERO;
            $discountAmount = $this->money($data['discount_amount'] ?? $this->persistedMoney($salesReturn, 'discount_amount'));
            $cogsTotalAmount = DecimalMoney::ZERO;
            $preparedLines = null;

            if ($lines !== null) {
                $preparedLines = [];
                foreach ($lines as $line) {
                    $amounts = $this->lineAmounts($line);
                    $preparedLines[] = array_merge($line, $amounts);
                    $subTotal = DecimalMoney::add($subTotal, $amounts['amount']);
                    $taxAmount = DecimalMoney::add($taxAmount, $amounts['tax']);
                    $cogsTotalAmount = DecimalMoney::add($cogsTotalAmount, $amounts['cogs_amount']);
                }
            } else {
                $subTotal = $this->persistedMoney($salesReturn, 'sub_total');
                $taxAmount = $this->persistedMoney($salesReturn, 'tax_amount');
                $cogsTotalAmount = $this->persistedMoney($salesReturn, 'cogs_total_amount');
            }

            $totalAmount = DecimalMoney::add(
                DecimalMoney::subtract($subTotal, $discountAmount),
                $taxAmount,
            );
            $paymentMethod = $data['payment_method'] ?? $salesReturn->payment_method ?? 'reduce_receivable';
            $isInward = isset($data['is_inward']) ? (bool) $data['is_inward'] : (isset($data['is_import_slip']) ? (bool) $data['is_import_slip'] : $salesReturn->is_inward);
            $isDecreaseDebt = isset($data['is_decrease_debt']) ? (bool) $data['is_decrease_debt'] : ($paymentMethod === 'reduce_receivable');

            CommercialDraftAccountEvidenceGate::assertSatisfied('sales_return', $lines ?? $salesReturn->lines->map(
                static fn ($line): array => $line->toArray(),
            )->all(), [
                'inward' => $isInward,
            ]);

            $salesReturn->update([
                'branch_id' => $data['branch_id'] ?? $salesReturn->branch_id,
                'customer_id' => $data['customer_id'] ?? $salesReturn->customer_id,
                'customer_name' => $data['customer_name'] ?? $salesReturn->customer_name,
                'customer_address' => $data['customer_address'] ?? $salesReturn->customer_address,
                'tax_code' => $data['tax_code'] ?? $salesReturn->tax_code,
                'receiver_name' => $data['receiver_name'] ?? $salesReturn->receiver_name,
                'employee_id' => $data['employee_id'] ?? $salesReturn->employee_id,
                'voucher_type' => $data['voucher_type'] ?? $salesReturn->voucher_type,
                'payment_method' => $paymentMethod,
                'bank_account_id' => $data['bank_account_id'] ?? $salesReturn->bank_account_id,
                'voucher_number' => $data['voucher_number'] ?? $salesReturn->voucher_number,
                'voucher_date' => $data['voucher_date'] ?? $salesReturn->voucher_date,
                'accounting_date' => $data['accounting_date'] ?? $salesReturn->accounting_date,
                'reason' => $data['reason'] ?? $salesReturn->reason,
                'description' => $data['description'] ?? $data['reason'] ?? $salesReturn->description,
                'attached_docs' => $data['attached_docs'] ?? $salesReturn->attached_docs,
                'sub_total' => $subTotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'grand_total' => $totalAmount,
                'cogs_total_amount' => $cogsTotalAmount,
                'is_inward' => $isInward,
                'is_import_slip' => $isInward,
                'is_decrease_debt' => $isDecreaseDebt,
                'status' => $data['status'] ?? $salesReturn->status,
                'reference_invoice_id' => $data['reference_invoice_id'] ?? $salesReturn->reference_invoice_id,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $salesReturn->referenced_vouchers,
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            if ($lines !== null) {
                $salesReturn->lines()->delete();

                $defaultCredit = match ($paymentMethod) {
                    'cash' => '1111',
                    'bank' => '1121',
                    default => '131',
                };

                foreach ($preparedLines ?? [] as $idx => $line) {
                    $salesReturn->lines()->create([
                        'line_order' => $line['line_order'] ?? ($idx + 1),
                        'item_id' => $line['item_id'] ?? null,
                        'item_code' => $line['item_code'] ?? null,
                        'item_name' => $line['item_name'] ?? $line['description'] ?? null,
                        'description' => $line['description'] ?? $line['item_name'] ?? $salesReturn->reason ?? 'Trả lại hàng bán',
                        'unit' => $line['unit'] ?? null,
                        'warehouse_id' => $line['warehouse_id'] ?? null,
                        'debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'debit_account', 'sales_return', '5212'),
                        'credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'credit_account', 'sales_return', $defaultCredit),
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'amount' => $line['amount'],
                        'tax_rate' => $line['tax_rate'],
                        'tax_amount' => $line['tax'],
                        'tax_account' => CommercialDraftAccountEvidenceGate::account($line, 'tax_account', 'sales_return', '33311'),
                        'inventory_account' => CommercialDraftAccountEvidenceGate::account($line, 'inventory_account', 'sales_return', $line['cogs_debit_account'] ?? '1561'),
                        'cogs_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_account', 'sales_return', $line['cogs_credit_account'] ?? '632'),
                        'cogs_debit_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_debit_account', 'sales_return', $line['inventory_account'] ?? '1561'),
                        'cogs_credit_account' => CommercialDraftAccountEvidenceGate::account($line, 'cogs_credit_account', 'sales_return', $line['cogs_account'] ?? '632'),
                        'cogs_price' => $line['cogs_price'],
                        'cogs_unit_price' => $line['cogs_unit_price'],
                        'cogs_amount' => $line['cogs_amount'],
                        'invoice_number' => $line['invoice_number'] ?? null,
                        'invoice_date' => $line['invoice_date'] ?? null,
                        'sales_order_id' => $line['sales_order_id'] ?? null,
                        'contract_id' => $line['contract_id'] ?? null,
                    ]);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $salesReturn->syncReferences($data['referenced_vouchers']);
            }

            return $salesReturn->load(['lines.item', 'lines.warehouse', 'customer', 'employee', 'bankAccount', 'references']);
        });
    }

    private function money(mixed $value): string
    {
        return DecimalMoney::normalize($value ?? DecimalMoney::ZERO);
    }

    private function persistedMoney(object $model, string $attribute): string
    {
        return $this->money($model->getRawOriginal($attribute) ?? DecimalMoney::ZERO);
    }

    /**
     * Normalize one sales-return line once so create, update and posting use
     * the same exact decimal values.
     *
     * @return array{quantity:string,unit_price:string,amount:string,tax_rate:string,tax:string,cogs_price:string,cogs_unit_price:string,cogs_amount:string}
     */
    private function lineAmounts(array $line): array
    {
        $quantity = $this->money($line['quantity'] ?? '1');
        $unitPrice = $this->money($line['unit_price'] ?? '0');
        $rawAmount = trim((string) ($line['amount'] ?? ''));
        $amount = $rawAmount !== ''
            ? $this->money($rawAmount)
            : DecimalMoney::multiply($quantity, $unitPrice);
        $taxRate = $this->money($line['tax_rate'] ?? '0');
        $rawTax = trim((string) ($line['tax_amount'] ?? ''));
        $tax = $rawTax !== ''
            ? $this->money($rawTax)
            : DecimalMoney::percentage($amount, $taxRate);
        $cogsPrice = $this->money($line['cogs_unit_price'] ?? $line['cogs_price'] ?? '0');
        $rawCogsAmount = trim((string) ($line['cogs_amount'] ?? ''));
        $cogsAmount = $rawCogsAmount !== ''
            ? $this->money($rawCogsAmount)
            : DecimalMoney::multiply($quantity, $cogsPrice);

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'tax_rate' => $taxRate,
            'tax' => $tax,
            'cogs_price' => $cogsPrice,
            'cogs_unit_price' => $cogsPrice,
            'cogs_amount' => $cogsAmount,
        ];
    }

    public function delete(int|string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $salesReturn = SalesReturn::withoutGlobalScope('company')
                ->where('company_id', $this->resolveCompanyId([]))
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $salesReturn->company_id, $salesReturn->accounting_date ?? $salesReturn->voucher_date, 'xóa chứng từ trả lại hàng bán');
            $this->assertMutableSource($salesReturn, 'xóa');
            $salesReturn->lines()->delete();
            $salesReturn->references()->delete();
            $salesReturn->delete();

            return true;
        });
    }

    private function assertMutableSource(SalesReturn $source, string $operation): void
    {
        $status = strtolower(trim((string) $source->status));
        if ($source->is_posted || in_array($status, ['posted', 'voided', 'cancelled', 'canceled'], true)) {
            throw new ConflictHttpException("Không thể {$operation} chứng từ đã ghi sổ hoặc đã hủy. Hãy sử dụng thao tác bỏ ghi sổ/hủy riêng.");
        }
    }

    public function post(int|string $id): SalesReturn
    {
        return DB::transaction(function () use ($id) {
            $salesReturn = SalesReturn::withoutGlobalScope('company')
                ->where('company_id', $this->resolveCompanyId([]))
                ->with('lines')
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $salesReturn->company_id, $salesReturn->accounting_date ?? $salesReturn->voucher_date, 'ghi sổ chứng từ trả lại hàng bán');
            if ($salesReturn->is_posted) {
                throw new Exception('Chứng từ đã được ghi sổ.');
            }
            app(CommercialAdjustmentCapacityService::class)->assertSalesReturn($salesReturn);

            $policy = null;
            $accountMappings = null;
            if ((bool) config('accounting.enforce_return_discount_posting_account_mappings', true)) {
                try {
                    $policy = $this->accountingPolicyResolver->requireForVoucher(
                        (int) $salesReturn->company_id,
                        ($salesReturn->accounting_date ?? $salesReturn->voucher_date ?? now())->toDateString(),
                        SystemVoucherType::SALES_RETURN,
                    );
                } catch (AccountingPolicyUnavailableException $exception) {
                    throw ValidationException::withMessages([
                        'account_mappings' => 'Không thể ghi sổ trả lại/giảm giá khi chưa có mapping tài khoản/policy được phê duyệt.',
                    ]);
                }
                $accountMappings = $this->accountMappingGate->requireSatisfied($salesReturn, $policy);
            }

            $paymentMethod = strtolower($salesReturn->payment_method ?? 'reduce_receivable');
            $sourceCreditAccount = match ($paymentMethod) {
                'bank' => '1121',
                'cash' => '1111',
                default => '131',
            };
            $creditAccount = $accountMappings === null
                ? $sourceCreditAccount
                : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'settlement_credit', $salesReturn, null, $sourceCreditAccount);

            $glLines = [];

            // 1. Revenue & Output Tax Reduction
            // Nợ 5212 (Hàng bán trả lại) per line
            $revenueReductionByAccount = [];
            foreach ($salesReturn->lines as $line) {
                $sourceDebitAccount = $line->debit_account ?: '5212';
                $debitAcc = $accountMappings === null
                    ? $sourceDebitAccount
                    : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'revenue_reduction', $salesReturn, $line, $sourceDebitAccount);
                $lineAmt = $this->persistedMoney($line, 'amount');
                $revenueReductionByAccount[$debitAcc] = DecimalMoney::add(
                    $revenueReductionByAccount[$debitAcc] ?? DecimalMoney::ZERO,
                    $lineAmt,
                );
            }

            foreach ($revenueReductionByAccount as $acc => $amt) {
                if (DecimalMoney::compare($amt, DecimalMoney::ZERO) > 0) {
                    $glLines[] = [
                        'account_code' => $acc,
                        'description' => $salesReturn->reason ?: 'Hàng bán bị trả lại',
                        'debit_amount' => $amt,
                        'credit_amount' => 0,
                    ];
                }
            }

            // Nợ 33311 (Thuế GTGT đầu ra giảm). Keep one line per mapped VAT
            // account so a multi-line return cannot silently attach all tax to
            // the first line's account context.
            $taxByAccount = [];
            foreach ($salesReturn->lines as $line) {
                $lineTax = $this->persistedMoney($line, 'tax_amount');
                if (DecimalMoney::compare($lineTax, DecimalMoney::ZERO) <= 0) {
                    continue;
                }
                $sourceTaxAccount = $line->tax_account ?: '33311';
                $taxAccount = $accountMappings === null
                    ? $sourceTaxAccount
                    : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'output_vat_reduction', $salesReturn, $line, $sourceTaxAccount);
                $taxByAccount[$taxAccount] = DecimalMoney::add(
                    $taxByAccount[$taxAccount] ?? DecimalMoney::ZERO,
                    $lineTax,
                );
            }
            foreach ($taxByAccount as $taxAccount => $taxAmount) {
                $glLines[] = [
                    'account_code' => $taxAccount,
                    'description' => 'Thuế GTGT giảm do hàng bán trả lại',
                    'debit_amount' => $taxAmount,
                    'credit_amount' => 0,
                ];
            }

            // Có 131 / 1111 / 1121 (Tổng tiền giảm trừ)
            $persistedTotal = $this->persistedMoney($salesReturn, 'total_amount');
            $totalCreditAmt = DecimalMoney::compare($persistedTotal, DecimalMoney::ZERO) !== 0
                ? $persistedTotal
                : DecimalMoney::add(
                    $this->persistedMoney($salesReturn, 'sub_total'),
                    $this->persistedMoney($salesReturn, 'tax_amount'),
                );
            $glLines[] = [
                'account_code' => $creditAccount,
                'description' => $salesReturn->reason ?: 'Giảm trừ công nợ/tiền hàng bán trả lại',
                'debit_amount' => 0,
                'credit_amount' => $totalCreditAmt,
            ];

            // 2. Inventory Inward & COGS Reduction if is_inward / is_import_slip is true
            if ($salesReturn->is_inward || $salesReturn->is_import_slip) {
                foreach ($salesReturn->lines as $line) {
                    $cogsPrice = $this->money($line->getRawOriginal('cogs_price') ?: ($line->getRawOriginal('cogs_unit_price') ?: DecimalMoney::ZERO));
                    $persistedCogsAmount = $this->persistedMoney($line, 'cogs_amount');
                    $cogsAmt = DecimalMoney::compare($persistedCogsAmount, DecimalMoney::ZERO) !== 0
                        ? $persistedCogsAmount
                        : DecimalMoney::multiply(
                            $this->money($line->getRawOriginal('quantity') ?? '0'),
                            $cogsPrice,
                        );

                    if (DecimalMoney::compare($cogsAmt, DecimalMoney::ZERO) > 0) {
                        // Nợ 1561 (Nhập lại kho)
                        $glLines[] = [
                            'account_code' => $accountMappings === null
                                ? ($line->inventory_account ?: ($line->cogs_debit_account ?: '1561'))
                                : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'inventory_debit', $salesReturn, $line, $line->inventory_account ?: ($line->cogs_debit_account ?: '1561')),
                            'description' => 'Nhập kho hàng bán trả lại',
                            'debit_amount' => $cogsAmt,
                            'credit_amount' => 0,
                        ];
                        // Có 632 (Ghi giảm giá vốn)
                        $glLines[] = [
                            'account_code' => $accountMappings === null
                                ? ($line->cogs_account ?: ($line->cogs_credit_account ?: '632'))
                                : CommercialAdjustmentAccountMappingPostingGate::accountFor($accountMappings, 'cogs_credit', $salesReturn, $line, $line->cogs_account ?: ($line->cogs_credit_account ?: '632')),
                            'description' => 'Giảm giá vốn hàng bán trả lại',
                            'debit_amount' => 0,
                            'credit_amount' => $cogsAmt,
                        ];
                    }
                }
            }

            $postingDate = $salesReturn->accounting_date ? $salesReturn->accounting_date->toDateString() : now()->toDateString();
            $voucherDate = $salesReturn->voucher_date ? $salesReturn->voucher_date->toDateString() : now()->toDateString();

            // Create Journal Entry — generate unique GL voucher number to allow re-posting
            $jeBaseNumber = 'GL-'.$salesReturn->voucher_number;
            $existingCount = JournalEntry::where('company_id', $salesReturn->company_id)
                ->where('voucher_number', 'like', $jeBaseNumber.'%')
                ->count();
            $jeVoucherNumber = $existingCount > 0
                ? $jeBaseNumber.'-R'.$existingCount
                : $jeBaseNumber;

            $je = $this->journalEntryService->createPosted([
                'company_id' => $salesReturn->company_id,
                'voucher_type' => 'sales_return',
                'voucher_number' => $jeVoucherNumber,
                'voucher_date' => $voucherDate,
                'posting_date' => $postingDate,
                'description' => $salesReturn->reason ?? 'Trả lại hàng bán '.$salesReturn->voucher_number,
                'total_amount' => $totalCreditAmt,
                'status' => 'posted',
                'source_document_type' => SalesReturn::class,
                'source_document_id' => $salesReturn->id,
                'lines' => $glLines,
            ]);

            $salesReturn->journal_entry_id = $je->id;
            $salesReturn->is_posted = true;
            $salesReturn->status = 'posted';
            $salesReturn->save();
            $this->adjustmentSettlements->createFor($salesReturn);

            if ($accountMappings !== null) {
                $this->auditService->record($salesReturn, 'sales_return.account_mappings_applied', [], [], null, [
                    'journal_entry_id' => $je->id,
                    'account_mapping_gate' => 'enforced',
                    'account_mappings' => $accountMappings,
                ]);
            }

            return $salesReturn->load(['lines.item', 'lines.warehouse', 'customer', 'employee', 'bankAccount', 'references', 'journalEntry']);
        });
    }

    private function assertCanAutoPost(): void
    {
        if (! auth()->check() || ! auth()->user()->can('sales.returns.post')) {
            throw new AuthorizationException('Posting a sales return requires the dedicated posting permission.');
        }
    }

    private function assertDraftMutationIntent(array $data): void
    {
        if (! empty($data['is_posted']) || ($data['status'] ?? '') === 'posted') {
            throw new AuthorizationException('Posting must use the dedicated sales-return posting action.');
        }
    }

    public function unpost(int|string $id): SalesReturn
    {
        return DB::transaction(function () use ($id) {
            $salesReturn = SalesReturn::withoutGlobalScope('company')
                ->where('company_id', $this->resolveCompanyId([]))
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $salesReturn->company_id, $salesReturn->accounting_date ?? $salesReturn->voucher_date, 'bỏ ghi sổ chứng từ trả lại hàng bán');
            if (! $salesReturn->is_posted) {
                throw new Exception('Chứng từ chưa được ghi sổ.');
            }
            $this->assertHasNoPostedSettlementAllocations((int) $salesReturn->company_id, 'sales_return', (int) $salesReturn->id);
            $this->assertNoPostedDependentDocuments((int) $salesReturn->company_id, SalesReturn::class, (int) $salesReturn->id);

            if ($salesReturn->journal_entry_id) {
                $this->journalEntryService->void($salesReturn->journal_entry_id, (int) $salesReturn->company_id);
            }

            $salesReturn->is_posted = false;
            $salesReturn->status = 'draft';
            CommercialSourceAuditContext::mark($salesReturn, 'unposted');
            $salesReturn->save();

            return $salesReturn->load(['lines.item', 'lines.warehouse', 'customer', 'employee', 'bankAccount', 'references']);
        });
    }

    public function void(int|string $id): SalesReturn
    {
        return DB::transaction(function () use ($id) {
            $salesReturn = SalesReturn::withoutGlobalScope('company')
                ->where('company_id', $this->resolveCompanyId([]))
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $salesReturn->company_id, $salesReturn->accounting_date ?? $salesReturn->voucher_date, 'hủy chứng từ trả lại hàng bán');
            $this->assertHasNoPostedSettlementAllocations((int) $salesReturn->company_id, 'sales_return', (int) $salesReturn->id);
            $this->assertNoPostedDependentDocuments((int) $salesReturn->company_id, SalesReturn::class, (int) $salesReturn->id);

            if ($salesReturn->journal_entry_id) {
                $this->journalEntryService->void($salesReturn->journal_entry_id, (int) $salesReturn->company_id);
            }

            $salesReturn->is_posted = false;
            $salesReturn->status = 'voided';
            CommercialSourceAuditContext::mark($salesReturn, 'voided');
            $salesReturn->save();

            return $salesReturn->load(['lines.item', 'lines.warehouse', 'customer', 'employee', 'bankAccount', 'references']);
        });
    }

    public function duplicate(int|string $id): SalesReturn
    {
        return DB::transaction(function () use ($id) {
            $original = SalesReturn::withoutGlobalScope('company')
                ->where('company_id', $this->resolveCompanyId([]))
                ->with('lines')
                ->findOrFail($id);
            $this->periodGuard->assertOpen((int) $original->company_id, now()->toDateString(), 'nhân bản chứng từ trả lại hàng bán');

            $newVoucherNumber = $this->generateNextCode($original->company_id);

            $newReturn = $original->replicate();
            $newReturn->voucher_number = $newVoucherNumber;
            $newReturn->is_posted = false;
            $newReturn->journal_entry_id = null;
            $newReturn->voucher_date = now()->toDateString();
            $newReturn->accounting_date = now()->toDateString();
            $newReturn->status = 'draft';
            CommercialSourceAuditContext::mark($newReturn, 'duplicated');
            $newReturn->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->sales_return_id = $newReturn->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newReturn->syncReferences($original->referenced_vouchers);
            }

            return $newReturn->load(['lines.item', 'lines.warehouse', 'customer', 'employee', 'bankAccount', 'references']);
        });
    }

    public function generateNextCode(?int $companyId = null, ?string $prefix = 'TLHB'): string
    {
        $companyId = $this->resolveCompanyId(['company_id' => $companyId]);
        $p = $prefix ?: 'TLHB';
        $latest = SalesReturn::where('company_id', $companyId)
            ->where('voucher_number', 'like', $p.'%')
            ->orderBy('id', 'desc')
            ->value('voucher_number');

        if ($latest && preg_match('/'.preg_quote($p, '/').'[-]?(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 5, '0', STR_PAD_LEFT);
        } else {
            $count = SalesReturn::where('company_id', $companyId)->count() + 1;
            $nextSeq = str_pad($count, 5, '0', STR_PAD_LEFT);
        }

        return $p.$nextSeq;
    }

    public function getNextCode(?int $companyId = null, ?string $prefix = 'TLHB'): string
    {
        return $this->generateNextCode($companyId, $prefix);
    }

    private function resolveCompanyId(array $data): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        $requestedCompanyId = $data['company_id'] ?? null;
        if ($actorCompanyId === null) {
            throw new AccessDeniedHttpException('Authenticated user is not assigned to a company.');
        }
        if ($requestedCompanyId !== null && (int) $requestedCompanyId !== (int) $actorCompanyId) {
            throw new AccessDeniedHttpException('The requested company does not belong to the authenticated user.');
        }

        return (int) $actorCompanyId;
    }
}
