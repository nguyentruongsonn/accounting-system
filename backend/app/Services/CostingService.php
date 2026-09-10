<?php

namespace App\Services;

use App\Models\CostAllocation;
use App\Models\JournalEntryLine;
use App\Models\ProductionOrder;
use App\Support\DecimalMoney;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CostingService
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

    /**
     * Tập hợp và phân bổ chi phí sản xuất cho một tháng
     */
    public function allocateCosts($company_id, $month, array $wipEndingByOrder = [])
    {
        $company_id = $this->requireCompanyId($company_id);
        $this->assertProductionPostingMappingsAvailable();

        return DB::transaction(function () use ($company_id, $month, $wipEndingByOrder) {
            $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $endDate = Carbon::createFromFormat('Y-m', $month)->endOfMonth();

            // This is deliberately the first domain control in the run.  A
            // re-allocation deletes/replaces CostAllocation evidence before a
            // journal entry is created, including the valid zero-line path.
            // The monthly allocation has no independent posting date, so its
            // accounting date is the month end used for the resulting JE.
            $this->periodGuard->assertOpen(
                (int) $company_id,
                $endDate,
                "tính giá thành tháng {$month}",
            );

            // Resolve and validate every caller-controlled order reference
            // before any historical allocation can be voided/replaced.  The
            // explicit company predicate is intentional: this service may be
            // invoked from a worker without an authenticated model scope.
            $orders = ProductionOrder::withoutGlobalScope('company')
                ->where('company_id', $company_id)
                ->whereIn('status', ['in_progress', 'completed'])
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereNull('end_date')
                        ->orWhereBetween('end_date', [$startDate, $endDate]);
                })
                ->get();

            if ($orders->isEmpty()) {
                throw new \InvalidArgumentException("Không có lệnh sản xuất nào trong tháng {$month}");
            }

            $orderIds = $orders->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            foreach (array_keys($wipEndingByOrder) as $orderId) {
                if (! ctype_digit((string) $orderId) || ! in_array((int) $orderId, $orderIds, true)) {
                    throw new \InvalidArgumentException('WIP ending references must belong to active production orders in the authenticated company.');
                }
            }

            // Hủy/Xóa các bút toán phân bổ cũ của tháng này nếu có (xử lý re-allocation)
            $oldAllocations = CostAllocation::where('company_id', $company_id)
                ->where('month', $month)
                ->get();

            foreach ($oldAllocations as $oldAlloc) {
                $before = $oldAlloc->getAttributes();
                if ($oldAlloc->journal_entry_id) {
                    $this->journalEntryService->void($oldAlloc->journal_entry_id, (int) $oldAlloc->company_id);
                }
                $oldAlloc->delete();
                $this->recordAudit($oldAlloc, 'cost_allocation.replaced', $before, [], ['month' => $month]);
            }

            // 2. Tập hợp chi phí phát sinh từ Sổ cái (Loại trừ bút toán phân bổ giá thành)
            // SQLite has no LEFT() scalar function.  Keep the aggregation in
            // the database (rather than changing its cost semantics in PHP),
            // but select the ANSI-ish SQLite spelling where required. MySQL,
            // PostgreSQL and SQL Server all support LEFT(account_code, 3).
            $accountPrefixExpression = DB::connection()->getDriverName() === 'sqlite'
                ? 'SUBSTR(account_code, 1, 3)'
                : 'LEFT(account_code, 3)';

            $costs = JournalEntryLine::whereHas('journalEntry', function ($q) use ($company_id, $startDate, $endDate) {
                $q->where('company_id', $company_id)
                    ->where('status', 'posted')
                    ->where('voucher_type', '!=', 'cost_allocation') // Loại trừ bút toán kết chuyển
                    ->whereBetween('posting_date', [$startDate, $endDate]);
            })
                ->where(function ($q) {
                    $q->where('account_code', 'like', '621%')
                        ->orWhere('account_code', 'like', '622%')
                        ->orWhere('account_code', 'like', '627%');
                })
                ->selectRaw("{$accountPrefixExpression} as prefix, SUM(debit_amount - credit_amount) as net_cost")
                ->groupBy('prefix')
                ->pluck('net_cost', 'prefix');

            // cost_allocations is a whole-unit BIGINT contract, whereas the
            // journal source is DECIMAL(20,2).  Rounding here would silently
            // destroy fractional source evidence.  Keep the production path
            // closed until an owner-approved allocation scale/rounding policy
            // exists; integer-valued sources retain the existing contract.
            if (config('accounting.enforce_costing_integer_allocation_evidence', true)) {
                foreach (['621', '622', '627'] as $prefix) {
                    $netCost = DecimalMoney::normalize($costs->get($prefix, '0') ?? '0');
                    if (! str_ends_with($netCost, '.00')) {
                        throw new \InvalidArgumentException(
                            "Cost allocation requires whole-unit {$prefix} source costs; fractional values require an approved allocation rounding policy.",
                        );
                    }
                }
            }

            $totalMaterial = (int) round($costs->get('621', 0));
            $totalLabor = (int) round($costs->get('622', 0));
            $totalOverhead = (int) round($costs->get('627', 0));

            $totalPlannedQty = $orders->sum('planned_quantity');
            $glLines = [];
            $allocations = [];

            // Biến theo dõi tổng đã phân bổ để bù chênh lệch làm tròn
            $allocatedMaterial = 0;
            $allocatedLabor = 0;
            $allocatedOverhead = 0;

            $orderCount = $orders->count();

            // WIP đầu kỳ (Lấy CostAllocation gần nhất của các LSX này)
            // The order IDs are tenant-scoped above, but this explicit
            // predicate keeps historical WIP evidence constrained even when
            // the service is executed outside an HTTP-authenticated context.
            $prevAllocations = CostAllocation::withoutGlobalScope('company')
                ->where('company_id', $company_id)
                ->whereIn('production_order_id', $orders->pluck('id'))
                ->where('month', '<', $month)
                ->orderBy('month', 'desc')
                ->get()
                ->keyBy('production_order_id');

            foreach ($orders as $index => $order) {
                $isLast = ($index === $orderCount - 1);
                $ratio = $totalPlannedQty > 0 ? ($order->planned_quantity / $totalPlannedQty) : (1 / $orderCount);

                if ($isLast) {
                    // LSX cuối cùng nhận phần còn lại để đảm bảo sum === total
                    $orderMaterial = $totalMaterial - $allocatedMaterial;
                    $orderLabor = $totalLabor - $allocatedLabor;
                    $orderOverhead = $totalOverhead - $allocatedOverhead;
                } else {
                    $orderMaterial = (int) round($totalMaterial * $ratio);
                    $orderLabor = (int) round($totalLabor * $ratio);
                    $orderOverhead = (int) round($totalOverhead * $ratio);

                    $allocatedMaterial += $orderMaterial;
                    $allocatedLabor += $orderLabor;
                    $allocatedOverhead += $orderOverhead;
                }

                // WIP đầu kỳ
                $prevAllocation = $prevAllocations->get($order->id);
                $wipBeginning = $prevAllocation ? $prevAllocation->wip_ending : 0;

                // WIP cuối kỳ
                $wipEnding = $order->status === 'completed' ? 0 : ($wipEndingByOrder[$order->id] ?? 0);

                // Tổng giá thành
                $totalCost = $wipBeginning + $orderMaterial + $orderLabor + $orderOverhead - $wipEnding;

                // Lưu record phân bổ
                $allocation = CostAllocation::create([
                    'company_id' => $company_id,
                    'production_order_id' => $order->id,
                    'month' => $month,
                    'direct_material_cost' => $orderMaterial,
                    'direct_labor_cost' => $orderLabor,
                    'manufacturing_overhead' => $orderOverhead,
                    'wip_beginning' => $wipBeginning,
                    'wip_ending' => $wipEnding,
                    'total_cost' => $totalCost,
                    'is_posted' => true,
                ]);

                $allocations[] = $allocation;

                // Chuẩn bị Bút toán KẾT CHUYỂN CHI PHÍ sang 154
                // Nợ 154 / Có 621, 622, 627
                $orderTotalExpense = $orderMaterial + $orderLabor + $orderOverhead;
                if ($orderTotalExpense > 0) {
                    $glLines[] = [
                        'account_code' => '154', // Chi phi SXKD do dang
                        'description' => "Kết chuyển chi phí SX LSX {$order->order_number}",
                        'debit_amount' => $orderTotalExpense,
                        'credit_amount' => 0,
                    ];
                    if ($orderMaterial > 0) {
                        $glLines[] = ['account_code' => '621', 'description' => "Kết chuyển CP NVL LSX {$order->order_number}", 'debit_amount' => 0, 'credit_amount' => $orderMaterial];
                    }
                    if ($orderLabor > 0) {
                        $glLines[] = ['account_code' => '622', 'description' => "Kết chuyển CP Nhân công LSX {$order->order_number}", 'debit_amount' => 0, 'credit_amount' => $orderLabor];
                    }
                    if ($orderOverhead > 0) {
                        $glLines[] = ['account_code' => '627', 'description' => "Kết chuyển CP SXC LSX {$order->order_number}", 'debit_amount' => 0, 'credit_amount' => $orderOverhead];
                    }
                }

                // Nhập kho Thành phẩm nếu hoàn thành (Nợ 155 / Có 154)
                if ($order->status === 'completed' && $totalCost > 0) {
                    $glLines[] = [
                        'account_code' => '155', // Thành phẩm (155 instead of 1551 as requested)
                        'description' => "Nhập kho thành phẩm LSX {$order->order_number}",
                        'debit_amount' => $totalCost,
                        'credit_amount' => 0,
                    ];
                    $glLines[] = [
                        'account_code' => '154',
                        'description' => "Nhập kho thành phẩm LSX {$order->order_number}",
                        'debit_amount' => 0,
                        'credit_amount' => $totalCost,
                    ];
                }
            }

            // Ghi sổ cái (Tất cả bút toán gom vào 1 Journal Entry cuối tháng)
            // A cost-allocation row is posted accounting evidence only when a
            // balanced GL entry exists.  Without this boundary a zero-cost
            // month could persist `is_posted=true` allocations with no
            // journal_entry_id, which cannot be reconciled or reversed.
            if ($glLines === []) {
                throw ValidationException::withMessages([
                    'accounting' => 'Không thể ghi sổ giá thành khi kỳ không tạo được dòng hạch toán GL.',
                ]);
            }

            $voucherDate = $endDate->toDateString();
            $je = $this->journalEntryService->createPosted([
                'company_id' => $company_id,
                'voucher_type' => 'cost_allocation',
                'voucher_number' => 'GL-CA-'.$month.'-'.time(),
                'voucher_date' => $voucherDate,
                'posting_date' => $voucherDate,
                'description' => "Kết chuyển và tính giá thành tháng {$month}",
                'total_amount' => 0,
                'status' => 'posted',
                'lines' => $glLines,
            ]);

            // Update journal_entry_id cho cac allocation
            $allocationIds = collect($allocations)->pluck('id');
            CostAllocation::whereIn('id', $allocationIds)->update(['journal_entry_id' => $je->id]);

            foreach ($allocations as $allocation) {
                $allocation->refresh();
                $this->recordAudit($allocation, 'cost_allocation.created', [], $allocation->getAttributes(), ['month' => $month]);
            }

            return $allocations;
        });
    }

    /**
     * The allocation posting route still contains legacy 154/621/622/627/155
     * literals. Integer-allocation evidence is a separate control and does
     * not approve those account mappings. Keep worker/diagnostic execution
     * available outside production, but never create a production JE from an
     * unapproved route.
     */
    private function assertProductionPostingMappingsAvailable(): void
    {
        if (in_array(strtolower((string) config('app.env')), ['production', 'prod'], true)) {
            throw ValidationException::withMessages([
                'account_mappings' => 'Không thể ghi sổ phân bổ giá thành trong production khi chưa có mapping tài khoản được phê duyệt.',
            ]);
        }
    }

    /**
     * Controllers resolve TenantContext, but costing also runs from workers
     * and commands. Preserve explicit trusted CLI calls while rejecting an
     * authenticated actor's attempt to operate on another company.
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

    /** @param array<string, mixed> $before @param array<string, mixed> $after @param array<string, mixed> $metadata */
    private function recordAudit(CostAllocation $allocation, string $action, array $before, array $after, array $metadata = []): void
    {
        $this->auditService->record($allocation, $action, $before, $after, null, array_merge([
            'domain' => 'costing',
            'operation' => $action,
        ], $metadata));
    }
}
