<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Models\CashInventory;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Services\AuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashInventoryController extends Controller
{
    use HandlesApiErrors;

    public function __construct(private readonly AuditService $auditService) {}

    public function nextCode(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $max = 0;
        CashInventory::query()->where('company_id', $companyId)->pluck('audit_number')->each(function ($value) use (&$max): void {
            if (preg_match('/^KK(\d+)$/i', (string) $value, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        });

        return response()->json(['data' => ['code' => 'KK'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT)]]);
    }

    /**
     * Return a cash book balance only when the caller names an active leaf
     * account and the server has posted journal-line evidence.  An empty
     * result is unavailable rather than an inferred zero balance.
     */
    public function bookBalance(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validate([
            'account_code' => ['required', 'string', 'max:20'],
            'as_of_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $accountCode = trim($validated['account_code']);
        $account = ChartOfAccount::query()
            ->where('company_id', $companyId)
            ->where('code', $accountCode)
            ->where('is_active', true)
            ->where('is_parent', false)
            ->first();

        if ($account === null) {
            return response()->json([
                'error' => 'Tài khoản tiền mặt không tồn tại, không hoạt động hoặc chưa phải tài khoản chi tiết.',
                'error_code' => 'CASH_BOOK_ACCOUNT_UNAVAILABLE',
            ], 422);
        }

        $aggregate = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.account_code', $accountCode)
            ->whereRaw('DATE(COALESCE(journal_entries.posting_date, journal_entries.voucher_date)) <= ?', [$validated['as_of_date']])
            ->selectRaw('COUNT(journal_entry_lines.id) AS line_count, SUM(journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) AS balance')
            ->first();

        if ((int) ($aggregate?->line_count ?? 0) === 0) {
            return response()->json([
                'error' => 'Chưa có bút toán tiền mặt đã ghi sổ đến ngày được chọn.',
                'error_code' => 'CASH_BOOK_BALANCE_UNAVAILABLE',
                'data' => [
                    'account_code' => $accountCode,
                    'as_of_date' => $validated['as_of_date'],
                    'status' => 'unavailable',
                    'line_count' => 0,
                ],
            ], 422);
        }

        return response()->json(['data' => [
            'account_code' => $accountCode,
            'account_name' => $account->name,
            'as_of_date' => $validated['as_of_date'],
            'balance' => (string) $aggregate->balance,
            'line_count' => (int) $aggregate->line_count,
            'status' => 'available',
            'calculation_basis' => 'posted_journal_lines',
            'certifying' => false,
        ]]);
    }

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);

        return response()->json(CashInventory::query()
            ->where('company_id', $companyId)
            ->with('lines')
            ->orderBy('audit_date', 'desc')
            ->get());
    }

    public function store(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validate([
            'audit_number' => [
                'required',
                'string',
                Rule::unique('cash_inventories', 'audit_number')
                    ->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'audit_date' => 'required|date',
            'purpose' => 'nullable|string',
            'currency' => 'nullable|string',
            'account_code' => 'required|string|max:20',
            'book_balance' => 'numeric',
            'actual_balance' => 'numeric',
            'difference' => 'numeric',
            'status' => 'string',
            'lines' => 'array',
        ]);

        $accountCode = trim((string) $validated['account_code']);
        if (! ChartOfAccount::query()
            ->where('company_id', $companyId)
            ->where('code', $accountCode)
            ->where('is_active', true)
            ->where('is_parent', false)
            ->exists()) {
            throw ValidationException::withMessages([
                'account_code' => 'Phải chọn tài khoản chi tiết đang hoạt động từ danh mục của công ty.',
            ]);
        }
        $validated['account_code'] = $accountCode;

        DB::beginTransaction();
        try {
            $validated['company_id'] = $companyId;
            $inventory = CashInventory::create($validated);

            if (isset($validated['lines'])) {
                foreach ($validated['lines'] as $line) {
                    $inventory->lines()->create([
                        'denomination' => $line['denomination'],
                        'quantity' => $line['quantity'],
                        'amount' => $line['amount'],
                    ]);
                }
            }
            $this->auditService->record($inventory, 'cash_inventory.created', [], $inventory->getAttributes(), null, [
                'domain' => 'cash_inventory',
                'operation' => 'cash_inventory.created',
                'line_count' => count($validated['lines'] ?? []),
            ]);
            DB::commit();

            return response()->json($inventory->load('lines'), 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->apiError($request, $e);
        }
    }

    public function show(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);

        return response()->json(CashInventory::query()
            ->where('company_id', $companyId)
            ->with('lines')
            ->findOrFail($id));
    }

    public function destroy(Request $request, int $id)
    {
        $companyId = TenantContext::companyId($request);
        DB::transaction(function () use ($companyId, $id): void {
            $inventory = CashInventory::query()
                ->where('company_id', $companyId)
                ->findOrFail($id);
            $before = $inventory->getAttributes();
            $inventory->delete();
            $this->auditService->record($inventory, 'cash_inventory.deleted', $before, [], null, [
                'domain' => 'cash_inventory',
                'operation' => 'cash_inventory.deleted',
            ]);
        });

        return response()->json(null, 204);
    }
}
