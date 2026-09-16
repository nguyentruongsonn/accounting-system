<?php

namespace App\Services;

use App\Models\AllocationLog;
use App\Models\ToolEquipment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ToolsEquipmentService
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

    public function createTool(array $data)
    {
        $data['company_id'] = $this->requireCompanyId(
            array_key_exists('company_id', $data) ? (int) $data['company_id'] : null,
        );
        $this->assertExplicitToolAccounts($data);

        return DB::transaction(function () use ($data) {
            $tool = ToolEquipment::create([
                'company_id' => $data['company_id'],
                'tool_code' => $data['tool_code'],
                'tool_name' => $data['tool_name'],
                'purchase_date' => $data['purchase_date'],
                'original_cost' => $data['original_cost'],
                'allocation_months' => $data['allocation_months'],
                'monthly_allocation' => round($data['original_cost'] / $data['allocation_months']),
                'accumulated_allocation' => 0,
                'remaining_value' => $data['original_cost'],
                // Legacy defaults remain available only outside production;
                // assertExplicitToolAccounts() rejects omitted mappings before
                // this transaction in production.
                'tool_account' => $data['tool_account'] ?? '242',
                'expense_account' => $data['expense_account'] ?? '6423',
                'is_active' => true,
            ]);

            $this->recordAudit($tool, 'tool_equipment.created', [], $tool->getAttributes());

            return $tool;
        });
    }

    public function runMonthlyAllocation($company_id, $month, $description = null)
    {
        $company_id = $this->requireCompanyId($company_id === null ? null : (int) $company_id);
        $this->assertProductionPostingMappingsAvailable();

        return DB::transaction(function () use ($company_id, $month, $description) {
            // Serialize same-company month-end runs before the legacy
            // AllocationLog exists() check; the table has no unique
            // (company_id, month) constraint.
            DB::table('companies')->where('id', $company_id)->lockForUpdate()->first();
            // The source balance and AllocationLog are changed before the
            // downstream JE exists.  Guard the month-end accounting date at
            // the service boundary so even a zero/early-return run cannot
            // alter closed-period source evidence.
            $voucherDate = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();
            $this->periodGuard->assertOpen(
                (int) $company_id,
                $voucherDate,
                "phân bổ công cụ dụng cụ tháng {$month}",
            );

            // The company lock makes the read-and-return idempotency boundary
            // safe even though the legacy table has no unique month key.
            $existing = AllocationLog::where('company_id', $company_id)
                ->where('month', $month)
                ->first();
            if ($existing) {
                return $existing;
            }

            // Lấy tất cả CCDC đang hoạt động và còn giá trị
            // Do not rely on the optional model global scope here: this run
            // receives a company ID already derived from the authenticated
            // principal and every selected tool must be explicitly bound to it.
            $tools = ToolEquipment::withoutGlobalScope('company')
                ->where('company_id', $company_id)
                ->where('is_active', true)
                ->where('remaining_value', '>', 0)
                ->get();

            $totalAllocation = 0;
            $glLines = [];

            foreach ($tools as $tool) {
                $this->assertToolValueInvariant($tool);
                $before = $tool->getAttributes();
                // Tính số phân bổ kỳ này
                $allocationAmount = $tool->monthly_allocation;
                if ($tool->remaining_value < $allocationAmount) {
                    $allocationAmount = $tool->remaining_value; // Phân bổ nốt phần còn lại
                }

                $totalAllocation += $allocationAmount;

                // Ghi nhận vào Journal Lines
                // Nợ Chi phí (6423, 6273...)
                $glLines[] = [
                    'account_code' => $tool->expense_account,
                    'description' => "Phân bổ CCDC {$tool->tool_code} tháng {$month}",
                    'debit_amount' => $allocationAmount,
                    'credit_amount' => 0,
                ];
                // Có Chi phí trả trước (242)
                $glLines[] = [
                    'account_code' => $tool->tool_account,
                    'description' => "Phân bổ CCDC {$tool->tool_code} tháng {$month}",
                    'debit_amount' => 0,
                    'credit_amount' => $allocationAmount,
                ];

                // Cập nhật giá trị CCDC
                $tool->accumulated_allocation += $allocationAmount;
                $tool->remaining_value -= $allocationAmount;
                if ($tool->remaining_value <= 0) {
                    $tool->remaining_value = 0;
                    $tool->is_active = false;
                }
                $tool->save();
                $this->assertToolValueInvariant($tool->fresh());
                $this->recordAudit($tool, 'tool_equipment.allocated', $before, $tool->getAttributes(), ['month' => $month]);
            }

            if ($totalAllocation == 0) {
                return null; // Không có gì để phân bổ
            }

            // Tạo Allocation Log
            $log = AllocationLog::forceCreate([
                'company_id' => $company_id,
                'month' => $month,
                'description' => $description ?? "Phân bổ CCDC tháng {$month}",
                'total_amount' => $totalAllocation,
                'is_posted' => true,
            ]);

            // Ghi sổ cái
            $je = $this->journalEntryService->createPosted([
                'company_id' => $company_id,
                'voucher_type' => 'tool_allocation',
                'voucher_number' => 'GL-CC-'.$month.'-'.time(),
                'voucher_date' => $voucherDate,
                'posting_date' => $voucherDate,
                'description' => $log->description,
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => AllocationLog::class,
                'source_document_id' => $log->id,
                'lines' => $glLines,
            ]);

            $log->journal_entry_id = $je->id;
            $log->save();

            $this->recordAudit($log, 'tool_allocation.run', [], $log->getAttributes(), ['month' => $month, 'tool_count' => $tools->count()]);

            return $log;
        });
    }

    /** @return array{month:string,voucher_date:string,tool_count:int,total_amount:int,lines:list<array<string,mixed>>} */
    public function previewMonthlyAllocation(int $companyId, string $month): array
    {
        $companyId = $this->requireCompanyId($companyId);
        $voucherDate = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();
        $tools = ToolEquipment::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('remaining_value', '>', 0)
            ->orderBy('tool_code')
            ->get();
        $lines = $tools->map(function (ToolEquipment $tool): array {
            $amount = min((int) $tool->remaining_value, (int) $tool->monthly_allocation);

            return [
                'tool_id' => $tool->id,
                'tool_code' => $tool->tool_code,
                'tool_name' => $tool->tool_name,
                'amount' => $amount,
                'remaining_after' => (int) $tool->remaining_value - $amount,
            ];
        })->values()->all();

        return [
            'month' => $month,
            'voucher_date' => $voucherDate,
            'tool_count' => count($lines),
            'total_amount' => array_sum(array_column($lines, 'amount')),
            'lines' => $lines,
        ];
    }

    /** @param array<string,mixed> $data */
    public function updateTool(int $companyId, int $id, array $data): ToolEquipment
    {
        $companyId = $this->requireCompanyId($companyId);

        return DB::transaction(function () use ($companyId, $id, $data): ToolEquipment {
            $tool = ToolEquipment::withoutGlobalScope('company')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $before = $tool->getAttributes();
            $effectiveDate = $data['purchase_date'] ?? $tool->purchase_date;
            $this->periodGuard->assertOpen($companyId, $effectiveDate, 'sửa công cụ dụng cụ');
            $originalCost = (int) ($data['original_cost'] ?? $tool->original_cost);
            $allocationMonths = (int) ($data['allocation_months'] ?? $tool->allocation_months);
            $accumulated = (int) $tool->accumulated_allocation;
            if ($accumulated > $originalCost) {
                throw ValidationException::withMessages(['original_cost' => 'Nguyên giá không được thấp hơn giá trị đã phân bổ.']);
            }
            $tool->forceFill([
                'tool_code' => $data['tool_code'] ?? $tool->tool_code,
                'tool_name' => $data['tool_name'] ?? $tool->tool_name,
                'purchase_date' => $data['purchase_date'] ?? $tool->purchase_date,
                'original_cost' => $originalCost,
                'allocation_months' => $allocationMonths,
                'monthly_allocation' => (int) round($originalCost / max($allocationMonths, 1)),
                'remaining_value' => $originalCost - $accumulated,
                'tool_account' => $data['tool_account'] ?? $tool->tool_account,
                'expense_account' => $data['expense_account'] ?? $tool->expense_account,
            ])->save();
            $this->assertToolValueInvariant($tool->fresh());
            $this->recordAudit($tool, 'tool_equipment.updated', $before, $tool->fresh()->getAttributes());

            return $tool->fresh();
        });
    }

    public function disableTool(int $companyId, int $id, ?string $reason = null): ToolEquipment
    {
        $companyId = $this->requireCompanyId($companyId);

        return DB::transaction(function () use ($companyId, $id, $reason): ToolEquipment {
            $tool = ToolEquipment::withoutGlobalScope('company')->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $before = $tool->getAttributes();
            $this->periodGuard->assertOpen($companyId, $tool->purchase_date, 'ngừng sử dụng công cụ dụng cụ');
            $tool->forceFill(['is_active' => false])->save();
            $this->assertToolValueInvariant($tool->fresh());
            $this->recordAudit($tool, 'tool_equipment.disabled', $before, $tool->fresh()->getAttributes(), ['reason' => $reason]);

            return $tool->fresh();
        });
    }

    public function writeOffTool(int $companyId, int $id, ?string $writeOffDate = null, ?string $reason = null): ToolEquipment
    {
        $companyId = $this->requireCompanyId($companyId);

        return DB::transaction(function () use ($companyId, $id, $writeOffDate, $reason): ToolEquipment {
            $tool = ToolEquipment::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($id);
            $this->periodGuard->assertOpen(
                $companyId,
                $writeOffDate ?? now()->toDateString(),
                'ghi giảm công cụ dụng cụ',
            );
            $this->assertToolValueInvariant($tool);

            if ((int) $tool->remaining_value === 0 && ! $tool->is_active) {
                return $tool;
            }

            $before = $tool->getAttributes();
            $writeOffAmount = (int) $tool->remaining_value;
            $tool->forceFill([
                'accumulated_allocation' => (int) $tool->original_cost,
                'remaining_value' => 0,
                'is_active' => false,
            ])->save();

            $this->assertToolValueInvariant($tool->fresh());
            $this->recordAudit(
                $tool,
                'tool_equipment.written_off',
                $before,
                $tool->fresh()->getAttributes(),
                ['reason' => $reason, 'write_off_date' => $writeOffDate, 'write_off_amount' => $writeOffAmount],
            );

            return $tool->fresh();
        });
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @param array<string, mixed> $metadata */
    private function recordAudit($model, string $action, array $before, array $after, array $metadata = []): void
    {
        $this->auditService->record($model, $action, $before, $after, null, array_merge([
            'domain' => 'tools_equipment',
            'operation' => $action,
        ], $metadata));
    }

    /**
     * This allocation route posts persisted tool mappings directly into the
     * GL. Until an approved mapping resolver is wired in, production must
     * fail closed rather than reusing legacy 242/6423 defaults.
     */
    private function assertProductionPostingMappingsAvailable(): void
    {
        if (in_array(strtolower((string) config('app.env')), ['production', 'prod'], true)) {
            throw ValidationException::withMessages([
                'account_mappings' => 'Không thể ghi sổ phân bổ công cụ dụng cụ trong production khi chưa có mapping tài khoản được phê duyệt.',
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function assertExplicitToolAccounts(array $data): void
    {
        if (! in_array(strtolower((string) config('app.env')), ['production', 'prod'], true)) {
            return;
        }

        foreach (['tool_account', 'expense_account'] as $field) {
            if (! is_scalar($data[$field] ?? null) || trim((string) $data[$field]) === '') {
                throw ValidationException::withMessages([
                    $field => 'Tài khoản mapping phải được cung cấp rõ ràng trong production; không tự động gán tài khoản mặc định.',
                ]);
            }
        }
    }

    private function assertToolValueInvariant(ToolEquipment $tool): void
    {
        $original = (int) $tool->original_cost;
        $allocated = (int) $tool->accumulated_allocation;
        $remaining = (int) $tool->remaining_value;

        if ($original < 0 || $allocated < 0 || $remaining < 0 || $allocated + $remaining !== $original) {
            throw ValidationException::withMessages([
                'tool_equipment' => 'CCDC không hợp lệ: nguyên giá phải bằng số đã phân bổ cộng giá trị còn lại.',
            ]);
        }
    }

    /**
     * Controllers resolve TenantContext, but this service also runs from
     * workers/commands. Preserve explicit trusted CLI calls while rejecting
     * an authenticated actor's attempt to operate on another company.
     */
    private function requireCompanyId(?int $companyId): int
    {
        $actor = auth()->user();
        $actorCompanyId = $actor?->company_id;
        $resolvedCompanyId = $companyId ?? $actorCompanyId;

        if ($resolvedCompanyId === null || (int) $resolvedCompanyId <= 0 || ($actor !== null && (int) $actorCompanyId <= 0)) {
            throw ValidationException::withMessages([
                'company_id' => 'An authenticated company context is required.',
            ]);
        }

        if ($actor !== null && (int) $resolvedCompanyId !== (int) $actorCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return (int) $resolvedCompanyId;
    }
}
