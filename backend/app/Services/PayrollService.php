<?php

namespace App\Services;

use App\Models\Payroll;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    protected JournalEntryService $journalEntryService;

    public function __construct(
        JournalEntryService $journalEntryService,
        private readonly AuditService $auditService,
        private readonly AccountingPeriodGuard $periodGuard,
    )
    {
        $this->journalEntryService = $journalEntryService;
    }

    public function getAll(int $companyId)
    {
        $companyId = $this->requireCompanyId($companyId);

        return Payroll::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->orderBy('voucher_date', 'desc')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(int $companyId, array $data): Payroll
    {
        return DB::transaction(function () use ($companyId, $data) {
            $companyId = $this->requireCompanyId($companyId);
            $this->assertExplicitPostingAccounts($data['lines'] ?? null);

            // The service, rather than an untrusted payload or ambient auth
            // scope, owns the tenant contract for source creation.
            $this->periodGuard->assertOpen(
                $companyId,
                $data['posting_date'] ?? $data['voucher_date'],
                'lập bảng lương',
            );

            $totalAmount = collect($data['lines'])->sum('net_salary');

            $payroll = Payroll::create([
                'company_id' => $companyId,
                'voucher_number' => $data['voucher_number'],
                'voucher_date' => $data['voucher_date'],
                'posting_date' => $data['posting_date'] ?? $data['voucher_date'],
                'month' => $data['month'],
                'description' => $data['description'] ?? null,
                'total_amount' => $totalAmount,
                'is_posted' => false,
            ]);

            foreach ($data['lines'] as $index => $line) {
                $employeeId = $line['employee_id'] ?? null;
                if ($employeeId !== null && ! DB::table('employees')->where('id', (int) $employeeId)->where('company_id', $companyId)->exists()) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.employee_id" => 'Nhân viên phải thuộc công ty đang đăng nhập.',
                    ]);
                }
                $payroll->lines()->create([
                    'employee_id' => $employeeId,
                    'employee_name' => $line['employee_name'],
                    'department' => $line['department'] ?? null,
                    'basic_salary' => $line['basic_salary'] ?? 0,
                    'allowance' => $line['allowance'] ?? 0,
                    'deduction' => $line['deduction'] ?? 0,
                    'net_salary' => $line['net_salary'],
                    // No account is inferred here.  A payroll draft may be
                    // captured before posting, but its eventual GL route must
                    // remain explicit evidence supplied by the caller.
                    'debit_account' => trim((string) $line['debit_account']),
                    'credit_account' => trim((string) $line['credit_account']),
                ]);
            }

            $this->recordAudit($payroll, 'payroll.created', [], $payroll->getAttributes(), ['line_count' => count($data['lines'])]);

            return $payroll->load('lines');
        });
    }

    public function post(int $companyId, int $id): Payroll
    {
        return DB::transaction(function () use ($companyId, $id) {
            $companyId = $this->requireCompanyId($companyId);

            // Resolve under the trusted tenant before inspecting its date.
            // A foreign raw ID is therefore indistinguishable from missing.
            $payroll = $this->payrollForCompany($companyId, $id, ['lines']);
            $this->periodGuard->assertOpen(
                $companyId,
                $payroll->posting_date ?? $payroll->voucher_date,
                'ghi sổ bảng lương',
            );
            $before = $payroll->getAttributes();
            if ($payroll->is_posted) {
                throw new \Exception('Voucher is already posted');
            }

            // Legacy rows can predate the service boundary. Re-validate their
            // persisted evidence before building a trusted posted JE; never
            // substitute an account at this point.
            $this->assertExplicitPostingAccounts($payroll->lines->map(
                static fn ($line): array => [
                    'debit_account' => $line->debit_account,
                    'credit_account' => $line->credit_account,
                ],
            )->all());

            $glLines = [];
            foreach ($payroll->lines as $line) {
                $glLines[] = [
                    'account_code' => $line->debit_account,
                    'description' => 'Chi phí lương '.$payroll->month.' - '.$line->employee_name,
                    'debit_amount' => $line->net_salary,
                    'credit_amount' => 0,
                ];
                $glLines[] = [
                    'account_code' => $line->credit_account,
                    'description' => 'Phải trả người lao động '.$payroll->month.' - '.$line->employee_name,
                    'debit_amount' => 0,
                    'credit_amount' => $line->net_salary,
                ];
            }

            $je = $this->journalEntryService->createPosted([
                'company_id' => $payroll->company_id,
                'voucher_type' => 'payroll',
                'voucher_number' => 'GL-PR-'.$payroll->voucher_number,
                'voucher_date' => $payroll->voucher_date,
                'posting_date' => \Illuminate\Support\Carbon::parse($payroll->posting_date ?? $payroll->voucher_date)->toDateString(),
                'description' => $payroll->description ?? 'Bảng lương tháng '.$payroll->month,
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => Payroll::class,
                'source_document_id' => $payroll->id,
                'lines' => $glLines,
            ]);

            $payroll->journal_entry_id = $je->id;
            $payroll->is_posted = true;
            $payroll->save();
            $this->recordAudit($payroll, 'payroll.posted', $before, $payroll->getAttributes());

            return $payroll;
        });
    }

    public function void(int $companyId, int $id): Payroll
    {
        return DB::transaction(function () use ($companyId, $id) {
            $companyId = $this->requireCompanyId($companyId);

            // Keep the source-period control independent of the legacy GL
            // now() posting-date semantic in post(); changing that semantic
            // needs a separately approved accounting-policy decision.
            $payroll = $this->payrollForCompany($companyId, $id);
            $this->periodGuard->assertOpen(
                $companyId,
                $payroll->posting_date ?? $payroll->voucher_date,
                'bỏ ghi sổ bảng lương',
            );
            $before = $payroll->getAttributes();
            if (! $payroll->is_posted) {
                throw new \Exception('Voucher is not posted yet');
            }

            if ($payroll->journal_entry_id) {
                $this->journalEntryService->void($payroll->journal_entry_id, $companyId);
            }

            $payroll->is_posted = false;
            $payroll->save();
            $this->recordAudit($payroll, 'payroll.unposted', $before, $payroll->getAttributes());

            return $payroll;
        });
    }

    /** @param array<int, string> $with */
    private function payrollForCompany(int $companyId, int $id, array $with = []): Payroll
    {
        $companyId = $this->requireCompanyId($companyId);

        return Payroll::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->with($with)
            ->findOrFail($id);
    }

    /**
     * Keep explicit worker/CLI company arguments supported when no actor is
     * present, but never let an authenticated caller select another tenant.
     * This is deliberately a boundary check rather than an accounting-policy
     * or account-mapping decision.
     */
    private function requireCompanyId(int $companyId): int
    {
        $actor = auth()->user();
        $actorCompanyId = $actor?->company_id;

        if ($companyId <= 0 || ($actor !== null && ((int) $actorCompanyId <= 0))) {
            throw ValidationException::withMessages([
                'company_id' => 'An authenticated company context is required.',
            ]);
        }

        if ($actor !== null && (int) $actorCompanyId !== $companyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }

    /**
     * Account defaults in the legacy payroll schema are not an approved
     * accounting mapping.  Refuse incomplete service-created source evidence
     * instead of allowing the database/application default to become a GL
     * instruction at posting time.
     *
     * @param mixed $lines
     */
    private function assertExplicitPostingAccounts(mixed $lines): void
    {
        if (! is_array($lines) || $lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Bảng lương phải có ít nhất một dòng định khoản với tài khoản Nợ/Có rõ ràng.',
            ]);
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account" => 'Tài khoản Nợ/Có của bảng lương phải được cung cấp tường minh; hệ thống không tự gán tài khoản.',
                ]);
            }

            $debitAccount = $line['debit_account'] ?? null;
            $creditAccount = $line['credit_account'] ?? null;
            if ((! is_int($debitAccount) && ! is_string($debitAccount))
                || trim((string) $debitAccount) === ''
                || (! is_int($creditAccount) && ! is_string($creditAccount))
                || trim((string) $creditAccount) === '') {
                throw ValidationException::withMessages([
                    "lines.{$index}.account" => 'Tài khoản Nợ/Có của bảng lương phải được cung cấp tường minh; hệ thống không tự gán tài khoản.',
                ]);
            }
        }
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @param array<string, mixed> $metadata */
    private function recordAudit(Payroll $payroll, string $action, array $before, array $after, array $metadata = []): void
    {
        $this->auditService->record($payroll, $action, $before, $after, null, array_merge([
            'domain' => 'payroll',
            'operation' => $action,
        ], $metadata));
    }
}
