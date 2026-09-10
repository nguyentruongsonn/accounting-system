<?php

namespace App\Services;

use App\Exceptions\AccountingAccountMappingUnavailableException;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\PeriodCloseReadinessSnapshot;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PeriodClosingService
{
    protected JournalEntryService $journalEntryService;

    public function __construct(
        JournalEntryService $journalEntryService,
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly AuditService $auditService,
        private readonly PeriodCloseReadinessService $periodCloseReadinessService,
        private readonly PeriodCloseSignoffPostingGate $periodCloseSignoffPostingGate,
        private readonly PeriodCloseAccountMappingResolver $periodCloseAccountMappingResolver,
    ) {
        $this->journalEntryService = $journalEntryService;
    }

    /**
     * Preview period closing calculations and suggested double-entry closing lines.
     */
    public function preview(int $companyId, string $fromDate, string $toDate, ?string $mappingDate = null): array
    {
        // Preview is read-only, but it still exposes tenant financial data.
        // Direct service callers must not use an authenticated actor to read
        // another company's posted journal lines.
        $companyId = $this->requireCompanyId($companyId);

        if (config('accounting.enforce_period_close_account_mappings', true)) {
            $lineage = $this->requireCloseMappingLineage($companyId, $mappingDate ?? $toDate);

            return $this->controlledPreview($companyId, $fromDate, $toDate, $lineage);
        }

        // Query posted transaction lines in the date range, excluding previous closing vouchers
        // Do not aggregate DECIMAL columns in the database here: SQLite (used
        // in regression tests) and some driver combinations return SUM() as an
        // IEEE-754 value once the total is large. Bring canonical DECIMAL values
        // back individually and aggregate them with DecimalMoney instead.
        $postedLines = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'l.journal_entry_id', '=', 'e.id')
            ->where('e.company_id', $companyId)
            ->where('e.status', 'posted')
            ->where('e.voucher_type', '!=', 'period_closing')
            ->whereNull('e.deleted_at')
            ->whereDate('e.posting_date', '>=', $fromDate)
            ->whereDate('e.posting_date', '<=', $toDate)
            ->select('l.account_code', 'l.debit_amount', 'l.credit_amount')
            ->get();

        /** @var array<string, array{total_debit: string, total_credit: string}> $lines */
        $lines = [];
        foreach ($postedLines as $postedLine) {
            $code = (string) $postedLine->account_code;
            $current = $lines[$code] ?? [
                'total_debit' => DecimalMoney::ZERO,
                'total_credit' => DecimalMoney::ZERO,
            ];
            $lines[$code] = [
                'total_debit' => DecimalMoney::add($current['total_debit'], (string) $postedLine->debit_amount),
                'total_credit' => DecimalMoney::add($current['total_credit'], (string) $postedLine->credit_amount),
            ];
        }

        $accounts = ChartOfAccount::where('company_id', $companyId)->get()->keyBy('code');

        $revenueItems = [];
        $expenseItems = [];
        $suggestedLines = [];

        // Monetary aggregates must remain fixed-scale strings from the database
        // through posting. A closing voucher is a financial-control boundary and
        // may not silently pass through IEEE-754 floats.
        $totalRevenue = DecimalMoney::ZERO;
        $totalExpenses = DecimalMoney::ZERO;

        // 1. Process Revenue (511, 515, 711) and Revenue Deductions (521)
        foreach ($lines as $code => $line) {
            $debit = $line['total_debit'];
            $credit = $line['total_credit'];
            $accName = $accounts[$code]->name ?? $code;

            // Revenue: 511, 515, 711 -> Normal balance is Credit. Net = Credit - Debit
            if (str_starts_with($code, '511') || str_starts_with($code, '515') || str_starts_with($code, '711')) {
                $netCredit = DecimalMoney::subtract($credit, $debit);
                if (DecimalMoney::compare($netCredit, DecimalMoney::ZERO) > 0) {
                    $totalRevenue = DecimalMoney::add($totalRevenue, $netCredit);
                    $revenueItems[] = [
                        'account_code' => $code,
                        'account_name' => $accName,
                        'amount' => $netCredit,
                    ];

                    $stepName = str_starts_with($code, '511') ? '1. Kết chuyển Doanh thu bán hàng' : (str_starts_with($code, '515') ? '2. Kết chuyển Doanh thu tài chính' : 'Kết chuyển Thu nhập khác');
                    $suggestedLines[] = [
                        'step' => $stepName,
                        'description' => "Kết chuyển {$accName} ({$code}) vào TK 911",
                        'debit_account' => $code,
                        'credit_account' => '911',
                        'amount' => $netCredit,
                    ];
                }
            }

            // Deductions: 521 -> Normal balance is Debit. Net = Debit - Credit
            if (str_starts_with($code, '521')) {
                $netDebit = DecimalMoney::subtract($debit, $credit);
                if (DecimalMoney::compare($netDebit, DecimalMoney::ZERO) > 0) {
                    $totalRevenue = DecimalMoney::subtract($totalRevenue, $netDebit);
                    $suggestedLines[] = [
                        'step' => 'Kết chuyển Giảm trừ doanh thu',
                        'description' => "Kết chuyển giảm trừ doanh thu ({$code}) vào TK 911",
                        'debit_account' => '911',
                        'credit_account' => $code,
                        'amount' => $netDebit,
                    ];
                }
            }

            // Expenses: 632, 635, 641, 642, 811, 821 -> Normal balance is Debit. Net = Debit - Credit
            if (
                str_starts_with($code, '632') || str_starts_with($code, '635') ||
                str_starts_with($code, '641') || str_starts_with($code, '642') ||
                str_starts_with($code, '811') || str_starts_with($code, '821')
            ) {
                $netDebit = DecimalMoney::subtract($debit, $credit);
                if (DecimalMoney::compare($netDebit, DecimalMoney::ZERO) > 0) {
                    $totalExpenses = DecimalMoney::add($totalExpenses, $netDebit);
                    $expenseItems[] = [
                        'account_code' => $code,
                        'account_name' => $accName,
                        'amount' => $netDebit,
                    ];

                    $stepName = match (true) {
                        str_starts_with($code, '632') => '3. Kết chuyển Giá vốn hàng bán',
                        str_starts_with($code, '635') => '4. Kết chuyển Chi phí tài chính',
                        str_starts_with($code, '641') => '5. Kết chuyển Chi phí bán hàng',
                        str_starts_with($code, '642') => '6. Kết chuyển Chi phí QLDN',
                        str_starts_with($code, '811') => '7. Kết chuyển Chi phí khác',
                        str_starts_with($code, '821') => '8. Kết chuyển Chi phí thuế TNDN',
                        default => 'Kết chuyển chi phí',
                    };

                    $suggestedLines[] = [
                        'step' => $stepName,
                        'description' => "Kết chuyển {$accName} ({$code}) vào TK 911",
                        'debit_account' => '911',
                        'credit_account' => $code,
                        'amount' => $netDebit,
                    ];
                }
            }
        }

        $netProfit = DecimalMoney::subtract($totalRevenue, $totalExpenses);

        // 3. Result Line (911 -> 4212)
        if (DecimalMoney::compare($netProfit, DecimalMoney::ZERO) > 0) {
            $suggestedLines[] = [
                'step' => '9. Kết chuyển Lợi nhuận sau thuế (Lãi)',
                'description' => 'Kết chuyển Lợi nhuận sau thuế chưa phân phối vào TK 4212',
                'debit_account' => '911',
                'credit_account' => '4212',
                'amount' => $netProfit,
            ];
        } elseif (DecimalMoney::compare($netProfit, DecimalMoney::ZERO) < 0) {
            $suggestedLines[] = [
                'step' => '9. Kết chuyển Lỗ hoạt động kinh doanh',
                'description' => 'Kết chuyển Lỗ sau thuế chưa phân phối vào TK 4212',
                'debit_account' => '4212',
                'credit_account' => '911',
                'amount' => DecimalMoney::abs($netProfit),
            ];
        }

        return [
            'company_id' => $companyId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'net_profit' => $netProfit,
            'revenue_items' => $revenueItems,
            'expense_items' => $expenseItems,
            'suggested_lines' => $suggestedLines,
            'execution_available' => false,
            'account_mapping_lineage' => null,
        ];
    }

    /**
     * Build closing lines exclusively from the approved exact-account contract.
     *
     * @param  array<string,mixed>  $lineage
     */
    private function controlledPreview(int $companyId, string $fromDate, string $toDate, array $lineage): array
    {
        $sourceAccounts = collect($lineage['source_accounts'])->keyBy('account_code');
        $sourceCodes = $sourceAccounts->keys()->all();
        $postedLines = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'l.journal_entry_id', '=', 'e.id')
            ->where('e.company_id', $companyId)
            ->where('e.status', 'posted')
            ->where('e.voucher_type', '!=', 'period_closing')
            ->whereNull('e.deleted_at')
            ->whereDate('e.posting_date', '>=', $fromDate)
            ->whereDate('e.posting_date', '<=', $toDate)
            ->whereIn('l.account_code', $sourceCodes)
            ->select('l.account_code', 'l.debit_amount', 'l.credit_amount')
            ->get();

        /** @var array<string,array{total_debit:string,total_credit:string}> $balances */
        $balances = [];
        foreach ($postedLines as $postedLine) {
            $code = (string) $postedLine->account_code;
            $current = $balances[$code] ?? [
                'total_debit' => DecimalMoney::ZERO,
                'total_credit' => DecimalMoney::ZERO,
            ];
            $balances[$code] = [
                'total_debit' => DecimalMoney::add($current['total_debit'], (string) $postedLine->debit_amount),
                'total_credit' => DecimalMoney::add($current['total_credit'], (string) $postedLine->credit_amount),
            ];
        }

        $accountNames = ChartOfAccount::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->whereIn('code', $sourceCodes)
            ->pluck('name', 'code');
        $resultAccount = (string) $lineage['accounts']['result_clearing'];
        $retainedEarningsAccount = (string) $lineage['accounts']['retained_earnings'];
        $totalRevenue = DecimalMoney::ZERO;
        $totalExpenses = DecimalMoney::ZERO;
        $revenueItems = [];
        $expenseItems = [];
        $suggestedLines = [];

        foreach ($lineage['source_accounts'] as $source) {
            $code = (string) $source['account_code'];
            $category = (string) $source['category'];
            $balance = $balances[$code] ?? [
                'total_debit' => DecimalMoney::ZERO,
                'total_credit' => DecimalMoney::ZERO,
            ];
            $debit = $balance['total_debit'];
            $credit = $balance['total_credit'];
            $netDebit = DecimalMoney::subtract($debit, $credit);
            $accountName = (string) ($accountNames[$code] ?? $code);

            if ($category === 'revenue') {
                $amount = DecimalMoney::subtract($credit, $debit);
                $totalRevenue = DecimalMoney::add($totalRevenue, $amount);
                if (DecimalMoney::compare($amount, DecimalMoney::ZERO) !== 0) {
                    $revenueItems[] = [
                        'account_code' => $code,
                        'account_name' => $accountName,
                        'amount' => $amount,
                    ];
                }
            } else {
                $amount = $netDebit;
                $totalExpenses = DecimalMoney::add($totalExpenses, $amount);
                if (DecimalMoney::compare($amount, DecimalMoney::ZERO) !== 0) {
                    $expenseItems[] = [
                        'account_code' => $code,
                        'account_name' => $accountName,
                        'amount' => $amount,
                    ];
                }
            }

            if (DecimalMoney::compare($netDebit, DecimalMoney::ZERO) > 0) {
                $suggestedLines[] = [
                    'step' => 'Kết chuyển '.$accountName,
                    'description' => "Kết chuyển {$accountName} ({$code}) vào TK {$resultAccount}",
                    'debit_account' => $resultAccount,
                    'credit_account' => $code,
                    'amount' => $netDebit,
                ];
            } elseif (DecimalMoney::compare($netDebit, DecimalMoney::ZERO) < 0) {
                $suggestedLines[] = [
                    'step' => 'Kết chuyển '.$accountName,
                    'description' => "Kết chuyển {$accountName} ({$code}) vào TK {$resultAccount}",
                    'debit_account' => $code,
                    'credit_account' => $resultAccount,
                    'amount' => DecimalMoney::abs($netDebit),
                ];
            }
        }

        $netProfit = DecimalMoney::subtract($totalRevenue, $totalExpenses);
        if (DecimalMoney::compare($netProfit, DecimalMoney::ZERO) > 0) {
            $suggestedLines[] = [
                'step' => 'Kết chuyển kết quả kinh doanh (Lãi)',
                'description' => "Kết chuyển lãi từ TK {$resultAccount} sang TK {$retainedEarningsAccount}",
                'debit_account' => $resultAccount,
                'credit_account' => $retainedEarningsAccount,
                'amount' => $netProfit,
            ];
        } elseif (DecimalMoney::compare($netProfit, DecimalMoney::ZERO) < 0) {
            $suggestedLines[] = [
                'step' => 'Kết chuyển kết quả kinh doanh (Lỗ)',
                'description' => "Kết chuyển lỗ từ TK {$resultAccount} sang TK {$retainedEarningsAccount}",
                'debit_account' => $retainedEarningsAccount,
                'credit_account' => $resultAccount,
                'amount' => DecimalMoney::abs($netProfit),
            ];
        }

        return [
            'company_id' => $companyId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'net_profit' => $netProfit,
            'revenue_items' => $revenueItems,
            'expense_items' => $expenseItems,
            'suggested_lines' => $suggestedLines,
            'execution_available' => true,
            'account_mapping_lineage' => $lineage,
        ];
    }

    /** @return array<string,mixed> */
    private function requireCloseMappingLineage(int $companyId, string $postingDate): array
    {
        try {
            return $this->periodCloseAccountMappingResolver->require($companyId, $postingDate);
        } catch (AccountingPolicyUnavailableException|AccountingAccountMappingUnavailableException $exception) {
            throw ValidationException::withMessages([
                'period_close_account_mappings' => 'Chưa thể đóng kỳ: chưa có mapping tài khoản kết chuyển được phê duyệt theo doanh nghiệp, chế độ kế toán và ngày hiệu lực.',
            ]);
        }
    }

    /**
     * Execute period closing, create and post closing JournalEntry voucher (idempotent).
     */
    public function executeForCompany(int $companyId, array $data): JournalEntry
    {
        $companyId = $this->requireCompanyId($companyId);
        $closeReason = $this->resolveCloseReason($data);
        $fromDate = $data['from_date'] ?? date('Y-m-01');
        $toDate = $data['to_date'] ?? date('Y-m-t');

        if (array_key_exists('lines', $data)) {
            throw ValidationException::withMessages([
                'lines' => 'Bút toán kết chuyển do máy chủ sinh từ sổ cái; không chấp nhận định khoản do client gửi.',
            ]);
        }

        if (strtotime($fromDate) === false || strtotime($toDate) === false || $fromDate > $toDate) {
            throw ValidationException::withMessages([
                'period' => 'Khoảng thời gian kết chuyển không hợp lệ.',
            ]);
        }

        $periodToClose = ! empty($data['period_id'])
            ? Period::whereHas('fiscalYear', fn ($query) => $query->where('company_id', $companyId))
                ->whereKey($data['period_id'])
                ->first()
            : Period::whereHas('fiscalYear', fn ($query) => $query->where('company_id', $companyId))
                ->where('start_date', '<=', $toDate)
                ->where('end_date', '>=', $toDate)
                ->first();

        if (! $periodToClose) {
            throw ValidationException::withMessages([
                'period_id' => ! empty($data['period_id'])
                    ? 'Kỳ kế toán không thuộc doanh nghiệp hiện tại.'
                    : 'Không xác định được kỳ kế toán để khóa. Hãy chọn kỳ kế toán thuộc doanh nghiệp và bao gồm ngày kết chuyển.',
            ]);
        }

        // Persist the evidence before the voucher transaction. A rejected close
        // must leave an immutable audit trail; otherwise its ValidationException
        // would roll the evidence back together with the voucher.
        $readiness = $this->resolveReadinessEvidence($companyId, $periodToClose, $data);
        if (! $readiness->eligible_to_close) {
            throw ValidationException::withMessages([
                'period_close_readiness' => 'Chưa đủ điều kiện khóa kỳ. Hệ thống chưa có bằng chứng đối chiếu được kiểm soát và close gate đang được áp dụng cho kỳ này.',
            ]);
        }
        if (! auth()->user()?->hasRole('admin')) {
            throw new AuthorizationException('Chỉ admin được kết chuyển và khóa kỳ kế toán.');
        }

        return DB::transaction(function () use ($companyId, $data, $periodToClose, $readiness, $closeReason) {
            $fromDate = $data['from_date'] ?? date('Y-m-01');
            $toDate = $data['to_date'] ?? date('Y-m-t');
            $voucherDate = $data['voucher_date'] ?? $toDate;
            $postingDate = $data['posting_date'] ?? $toDate;
            $voucherNumber = $data['voucher_number'] ?? ('KC-'.date('Y-m', strtotime($toDate)));
            $description = $data['description'] ?? "Bút toán kết chuyển xác định kết quả kinh doanh kỳ {$fromDate} đến {$toDate}";

            if (array_key_exists('lines', $data)) {
                throw ValidationException::withMessages([
                    'lines' => 'Bút toán kết chuyển do máy chủ sinh từ sổ cái; không chấp nhận định khoản do client gửi.',
                ]);
            }

            if (strtotime($fromDate) === false || strtotime($toDate) === false || $fromDate > $toDate) {
                throw ValidationException::withMessages([
                    'period' => 'Khoảng thời gian kết chuyển không hợp lệ.',
                ]);
            }

            // Lock both the accounting period and the exact evidence that was
            // reviewed.  The signoff package cannot be switched between the
            // readiness check and posting by a concurrent request.
            $lockedPeriod = Period::query()
                ->whereKey($periodToClose->id)
                ->whereHas('fiscalYear', fn ($query) => $query->where('company_id', $companyId))
                ->lockForUpdate()
                ->firstOrFail();
            $lockedReadiness = PeriodCloseReadinessSnapshot::withoutGlobalScope('company')
                ->whereKey($readiness->id)
                ->where('company_id', $companyId)
                ->where('period_id', $lockedPeriod->id)
                ->lockForUpdate()
                ->first();
            if ($lockedReadiness === null || ! $lockedReadiness->eligible_to_close) {
                throw ValidationException::withMessages([
                    'period_close_readiness' => 'Bằng chứng readiness không còn hợp lệ cho kỳ đang khóa.',
                ]);
            }
            $this->assertReadinessAfterLatestReopen($companyId, $lockedPeriod, $lockedReadiness);

            $existing = JournalEntry::where('company_id', $companyId)
                ->where('voucher_type', 'period_closing')
                // Idempotency is keyed by the caller/server-issued voucher
                // number.  A posting date is not a document identity: using
                // it as an OR match could return an unrelated close voucher
                // from the same day and falsely report a successful retry.
                ->where('voucher_number', $voucherNumber)
                ->first();

            if ($existing) {
                if ($existing->status === 'posted' && $existing->reversed_by_entry_id === null) {
                    return $existing->load('lines');
                }

                if ($existing->status === 'posted' && $existing->reversed_by_entry_id !== null) {
                    $revision = 1;
                    do {
                        $candidate = "{$voucherNumber}-R{$revision}";
                        $revision++;
                    } while (JournalEntry::withoutGlobalScope('company')
                        ->where('company_id', $companyId)
                        ->where('voucher_number', $candidate)
                        ->exists());
                    $voucherNumber = $candidate;
                } else {
                    throw new ConflictHttpException('Đã tồn tại chứng từ kết chuyển chưa hoàn tất cho kỳ này.');
                }
            }

            $this->periodGuard->assertRangeOpen($companyId, $fromDate, $toDate, 'kết chuyển và khóa kỳ');
            $this->periodGuard->assertOpen($companyId, $postingDate, 'ghi sổ bút toán kết chuyển');

            $signoffLineage = null;
            if (config('accounting.enforce_period_close_signoff', true)) {
                $signoffLineage = $this->periodCloseSignoffPostingGate->requireApproved(
                    $companyId,
                    $lockedPeriod,
                    $lockedReadiness,
                    auth()->id(),
                );
            }

            $preview = $this->preview($companyId, $fromDate, $toDate, $postingDate);
            $lines = $preview['suggested_lines'];
            if (empty($lines)) {
                throw new ConflictHttpException('Không có phát sinh doanh thu, chi phí cần kết chuyển trong kỳ này.');
            }

            $jeLines = [];
            foreach ($lines as $line) {
                $desc = $line['description'] ?? $description;
                $amt = DecimalMoney::normalize((string) ($line['amount'] ?? DecimalMoney::ZERO));
                if (DecimalMoney::compare($amt, DecimalMoney::ZERO) <= 0) {
                    continue;
                }

                if (isset($line['debit_account']) && isset($line['credit_account'])) {
                    $jeLines[] = [
                        'account_code' => $line['debit_account'],
                        'description' => $desc,
                        'debit_amount' => $amt,
                        'credit_amount' => DecimalMoney::ZERO,
                    ];
                    $jeLines[] = [
                        'account_code' => $line['credit_account'],
                        'description' => $desc,
                        'debit_amount' => DecimalMoney::ZERO,
                        'credit_amount' => $amt,
                    ];
                } else {
                    $jeLines[] = [
                        'account_code' => $line['account_code'] ?? '',
                        'description' => $desc,
                        'debit_amount' => DecimalMoney::normalize((string) ($line['debit_amount'] ?? DecimalMoney::ZERO)),
                        'credit_amount' => DecimalMoney::normalize((string) ($line['credit_amount'] ?? DecimalMoney::ZERO)),
                    ];
                }
            }

            $totalDebit = DecimalMoney::sum(array_column($jeLines, 'debit_amount'));
            $totalCredit = DecimalMoney::sum(array_column($jeLines, 'credit_amount'));
            if (empty($jeLines) || DecimalMoney::compare($totalDebit, $totalCredit) !== 0) {
                throw ValidationException::withMessages([
                    'closing_entry' => 'Bút toán kết chuyển do hệ thống sinh không cân bằng Nợ/Có.',
                ]);
            }

            $accountCodes = collect($jeLines)->pluck('account_code')->filter()->unique()->values();
            $validAccountCodes = ChartOfAccount::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('is_parent', false)
                ->whereIn('code', $accountCodes)
                ->pluck('code');

            $invalidAccountCodes = $accountCodes->diff($validAccountCodes)->values();
            if ($invalidAccountCodes->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'accounts' => 'Tài khoản kết chuyển không tồn tại, đã khóa hoặc không phải tài khoản chi tiết: '.$invalidAccountCodes->implode(', '),
                ]);
            }

            $closingVoucher = $this->journalEntryService->createPosted([
                'company_id' => $companyId,
                'voucher_type' => 'period_closing',
                'voucher_number' => $voucherNumber,
                'voucher_date' => $voucherDate,
                'posting_date' => $postingDate,
                'description' => $description,
                'status' => 'posted',
                'lines' => $jeLines,
            ]);

            $lockedPeriod->update(['is_closed' => true, 'status' => 'closed']);

            $this->auditService->record(
                $closingVoucher,
                'period.closed',
                [],
                [],
                null,
                [
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'posting_date' => $postingDate,
                    'period_id' => $lockedPeriod->id,
                    'closing_entry_id' => $closingVoucher->id,
                    // Keep both keys while clients migrate to close_reason;
                    // the audit record remains the source of truth for why
                    // the period was closed.
                    'close_reason' => $closeReason,
                    'reason' => $closeReason,
                ]
            );

            if ($signoffLineage !== null) {
                $this->auditService->record(
                    $closingVoucher,
                    'period_close.signoff_applied',
                    [],
                    [],
                    null,
                    [
                        'close_gate' => 'enforced',
                        'period_id' => $lockedPeriod->id,
                        'signoff' => $signoffLineage,
                    ],
                );
            }

            if (($preview['account_mapping_lineage'] ?? null) !== null) {
                $this->auditService->record(
                    $closingVoucher,
                    'period_close.account_mappings_applied',
                    [],
                    [],
                    null,
                    [
                        'account_mapping_gate' => 'enforced',
                        'period_id' => $lockedPeriod->id,
                        'account_mappings' => $preview['account_mapping_lineage'],
                    ],
                );
            }

            return $closingVoucher;
        });
    }

    /**
     * Compatibility wrapper for trusted in-process workflows. It deliberately
     * ignores any client-style company_id in $data and never falls back to a
     * default tenant.
     */
    public function execute(array $data): JournalEntry
    {
        $companyId = auth()->user()?->company_id;

        if ($companyId === null) {
            throw new AccessDeniedHttpException('Authenticated user is not assigned to a company.');
        }

        return $this->executeForCompany((int) $companyId, $data);
    }

    /**
     * A reviewed readiness snapshot is explicit evidence, not a client-side
     * authorization.  It remains tenant/period scoped and is re-locked and
     * fully revalidated in the posting transaction together with its signoff.
     */
    private function resolveReadinessEvidence(int $companyId, Period $period, array $data): PeriodCloseReadinessSnapshot
    {
        if (array_key_exists('period_close_readiness_snapshot_id', $data)) {
            $id = filter_var($data['period_close_readiness_snapshot_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw ValidationException::withMessages(['period_close_readiness_snapshot_id' => 'Mã bằng chứng readiness không hợp lệ.']);
            }

            $snapshot = PeriodCloseReadinessSnapshot::withoutGlobalScope('company')
                ->whereKey($id)
                ->where('company_id', $companyId)
                ->where('period_id', $period->id)
                ->first();
            if ($snapshot === null) {
                throw ValidationException::withMessages(['period_close_readiness_snapshot_id' => 'Bằng chứng readiness không thuộc doanh nghiệp hoặc kỳ hiện tại.']);
            }

            $this->assertReadinessAfterLatestReopen($companyId, $period, $snapshot);

            return $snapshot;
        }

        // Preserve the immutable denied-attempt evidence used by existing
        // operations. A successful controlled workflow supplies the reviewed
        // snapshot id produced before signoff preparation.
        return $this->periodCloseReadinessService->evaluate($companyId, $period->id, auth()->id());
    }

    private function assertReadinessAfterLatestReopen(
        int $companyId,
        Period $period,
        PeriodCloseReadinessSnapshot $snapshot,
    ): void {
        $latestReopen = AuditLog::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('model_type', $period->getMorphClass())
            ->where('model_id', $period->id)
            ->where('action', 'period.reopened')
            ->latest('id')
            ->first();
        $snapshotFloorId = (int) ($latestReopen?->metadata['readiness_snapshot_floor_id'] ?? 0);

        if ($latestReopen !== null && (int) $snapshot->id <= $snapshotFloorId) {
            throw ValidationException::withMessages([
                'period_close_readiness' => 'Kỳ đã được mở lại sau lần đánh giá này. Phải tính giá và chạy đối chiếu readiness mới trước khi khóa lại.',
            ]);
        }
    }

    private function resolveCloseReason(array $data): string
    {
        // reason remains an accepted alias for older in-process callers, but
        // close_reason is the canonical field persisted in the close audit.
        $rawReason = $data['close_reason'] ?? $data['reason'] ?? null;
        if (! is_string($rawReason)) {
            throw ValidationException::withMessages([
                'close_reason' => 'Phải nêu lý do khóa kỳ kế toán.',
            ]);
        }

        $closeReason = trim($rawReason);
        if ($closeReason === '') {
            throw ValidationException::withMessages([
                'close_reason' => 'Phải nêu lý do khóa kỳ kế toán.',
            ]);
        }
        if (mb_strlen($closeReason) > 2000) {
            throw ValidationException::withMessages([
                'close_reason' => 'Lý do khóa kỳ không được vượt quá 2000 ký tự.',
            ]);
        }

        return $closeReason;
    }

    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => 'A trusted company context is required.',
            ]);
        }
        if ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }
}
