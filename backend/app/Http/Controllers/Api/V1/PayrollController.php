<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PayrollService;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    protected PayrollService $service;

    public function __construct(PayrollService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        return response()->json($this->service->getAll($this->companyId($request)));
    }

    public function store(Request $request)
    {
        $request->validate([
            'voucher_number' => 'required|string',
            'voucher_date' => 'required|date',
            'posting_date' => 'nullable|date',
            'month' => 'required|string',
            'lines' => 'required|array|min:1',
        ]);

        // A payroll payload must not be able to select another company's
        // staff cost and payable ledger.
        $data = $request->all();
        $companyId = $this->companyId($request);

        $payroll = $this->service->create($companyId, $data);

        return response()->json($payroll, 201);
    }

    public function post(Request $request, int $id)
    {
        $payroll = $this->service->post($this->companyId($request), $id);

        return response()->json(['message' => 'Ghi sổ thành công', 'data' => $payroll]);
    }

    public function void(Request $request, int $id)
    {
        $payroll = $this->service->void($this->companyId($request), $id);

        return response()->json(['message' => 'Bỏ ghi sổ thành công', 'data' => $payroll]);
    }

    private function companyId(Request $request): int
    {
        abort_unless($request->user()?->company_id, 403, 'An authenticated company context is required.');

        return (int) $request->user()->company_id;
    }
}
