<?php

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Models\PurchaseContract;
use App\Models\PurchaseContractLine;
use App\Models\PurchaseContractPayment;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseContractController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseContract::with(['lines', 'payments', 'supplier', 'employee'])
            ->where('company_id', TenantContext::companyId($request))
            ->orderBy('id', 'desc');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('contract_number', 'like', "%{$s}%")
                    ->orWhere('contract_name', 'like', "%{$s}%")
                    ->orWhere('supplier_name', 'like', "%{$s}%");
            });
        }

        return response()->json($query->get());
    }

    public function nextCode(Request $request)
    {
        $companyId = TenantContext::companyId($request);
        $nextSequence = 1;

        // Do not reuse a contract number after deletion or let manually
        // numbered contracts consume this endpoint's sequence.
        foreach (PurchaseContract::where('company_id', $companyId)->pluck('contract_number') as $contractNumber) {
            if (preg_match('/^HĐM(\d+)$/u', (string) $contractNumber, $matches) === 1) {
                $nextSequence = max($nextSequence, ((int) $matches[1]) + 1);
            }
        }

        $code = 'HĐM'.str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);

        return response()->json([
            'code' => $code,
            'next_code' => $code,
            'data' => ['code' => $code, 'next_code' => $code],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'contract_number' => 'required',
            'signed_date' => 'required',
        ]);

        return DB::transaction(function () use ($request) {
            $data = $request->except(['lines', 'payments']);
            $data['company_id'] = TenantContext::companyId($request);

            $contract = PurchaseContract::create($data);

            if ($request->has('lines') && is_array($request->lines)) {
                foreach ($request->lines as $line) {
                    $line['purchase_contract_id'] = $contract->id;
                    PurchaseContractLine::create($line);
                }
            }

            if ($request->has('payments') && is_array($request->payments)) {
                foreach ($request->payments as $payment) {
                    $payment['purchase_contract_id'] = $contract->id;
                    PurchaseContractPayment::create($payment);
                }
            }

            return response()->json($contract->load(['lines', 'payments']), 201);
        });
    }

    public function show(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $contract = PurchaseContract::with(['lines', 'payments', 'supplier', 'employee'])
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json($contract);
    }

    public function update(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);
        $contract = PurchaseContract::where('company_id', $companyId)->findOrFail($id);

        return DB::transaction(function () use ($request, $contract) {
            // company_id is server-owned; accepting it here could move an
            // existing contract to another tenant after the scoped lookup.
            $data = $request->except(['lines', 'payments', 'company_id']);
            $contract->update($data);

            if ($request->has('lines') && is_array($request->lines)) {
                $contract->lines()->delete();
                foreach ($request->lines as $line) {
                    $line['purchase_contract_id'] = $contract->id;
                    PurchaseContractLine::create($line);
                }
            }

            if ($request->has('payments') && is_array($request->payments)) {
                $contract->payments()->delete();
                foreach ($request->payments as $payment) {
                    $payment['purchase_contract_id'] = $contract->id;
                    PurchaseContractPayment::create($payment);
                }
            }

            return response()->json($contract->load(['lines', 'payments']));
        });
    }

    public function destroy(Request $request, $id)
    {
        $companyId = TenantContext::companyId($request);

        DB::transaction(function () use ($id, $companyId) {
            $contract = PurchaseContract::where('company_id', $companyId)->findOrFail($id);
            $contract->lines()->delete();
            $contract->payments()->delete();
            $contract->delete();
        });

        return response()->json(['message' => 'Đã xóa hợp đồng mua hàng thành công']);
    }
}
