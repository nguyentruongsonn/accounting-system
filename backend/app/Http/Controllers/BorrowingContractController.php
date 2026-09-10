<?php

namespace App\Http\Controllers;

use App\Models\BorrowingContract;
use App\Services\AuditService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BorrowingContractController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        // Do not rely on BelongsToCompany's conditional global scope here:
        // an authenticated user without a company assignment would otherwise
        // receive every tenant's contracts.
        $query = BorrowingContract::withoutGlobalScope('company')
            ->where('company_id', $companyId);
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('contract_number', 'like', "%{$search}%")
                    ->orWhere('lender_name', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderByDesc('id')->get());
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->company_id, 403, 'An authenticated company context is required.');

        $validated = $request->validate([
            'contract_number' => 'required|string|unique:borrowing_contracts,contract_number',
            'lender_name' => 'required|string',
            'amount' => 'required|numeric',
            'disbursement_date' => 'required|date',
            'maturity_date' => 'required|date',
        ]);

        // The legacy schema has 3411/635 defaults, but those are not an
        // owner-approved borrowing mapping. Keep the old suggestion only in
        // non-production diagnostics; production must receive explicit
        // account evidence instead of silently creating a postable-looking
        // contract with inferred accounts.
        if (in_array(strtolower((string) config('app.env')), ['production', 'prod'], true)) {
            $missingAccounts = [];
            foreach (['debit_account', 'interest_account'] as $field) {
                $value = $request->input($field);
                if ((! is_int($value) && ! is_string($value)) || trim((string) $value) === '') {
                    $missingAccounts[$field] = 'Tài khoản phải được cung cấp tường minh trong môi trường production; hệ thống không tự gán tài khoản.';
                }
            }

            if ($missingAccounts !== []) {
                throw ValidationException::withMessages($missingAccounts);
            }
        }

        $contract = DB::transaction(function () use ($request, $validated): BorrowingContract {
            $contract = BorrowingContract::create([
                // The active tenant is authoritative; never accept a client
                // company ID when creating a liability contract.
                'company_id' => (int) $request->user()->company_id,
                'contract_number' => $validated['contract_number'],
                'credit_contract' => $request->input('credit_contract'),
                'lender_name' => $validated['lender_name'],
                'purpose' => $request->input('purpose'),
                'debit_account' => $request->input('debit_account', '3411'),
                'interest_account' => $request->input('interest_account', '635'),
                'amount' => $validated['amount'],
                'term' => $request->input('term', 12),
                'term_unit' => $request->input('term_unit', 'Tháng'),
                'disbursement_date' => $validated['disbursement_date'],
                'maturity_date' => $validated['maturity_date'],
                'disbursement_method' => $request->input('disbursement_method', 'Chuyển khoản vào tài khoản DN'),
                'recipient_account' => $request->input('recipient_account'),
                'recipient_bank' => $request->input('recipient_bank'),
                'interest_rate' => $request->input('interest_rate', 8.5),
                'interest_period' => $request->input('interest_period', 'Hàng tháng'),
                'paid_principal' => 0,
                'remaining_principal' => $request->input('amount', 0),
                'status' => 'Đang vay',
            ]);

            $this->auditService->record(
                $contract,
                'borrowing_contract.created',
                [],
                $contract->getAttributes(),
                null,
                ['domain' => 'borrowing_contract', 'operation' => 'created'],
            );

            return $contract;
        });

        return response()->json($contract, 201);
    }

    public function show(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $contract = BorrowingContract::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json($contract);
    }
}
