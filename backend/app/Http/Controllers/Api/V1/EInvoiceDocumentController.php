<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EInvoiceDocument;
use App\Services\EInvoiceLifecycleService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EInvoiceDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(EInvoiceDocument::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->orderByDesc('occurred_at')
            ->paginate((int) ($validated['per_page'] ?? 50)));
    }

    public function store(Request $request, EInvoiceLifecycleService $service): JsonResponse
    {
        $data = $request->validate([
            'accounting_document_type' => ['required', 'string'], 'accounting_document_id' => ['required', 'integer'],
            'lifecycle_status' => ['required', 'in:draft,issued,replaced,adjusted,cancelled'],
            'provider_name' => ['nullable', 'string', 'max:120'], 'provider_document_id' => ['nullable', 'string', 'max:190'],
            'document_reference' => ['nullable', 'string', 'max:190'], 'payload_snapshot' => ['nullable', 'array'],
            'supersedes_einvoice_document_id' => ['nullable', 'integer'], 'occurred_at' => ['nullable', 'date'],
        ]);
        $record = $service->record(TenantContext::companyId($request), (int) $request->user()->id, $data);

        return response()->json(['data' => $record, 'legal_compliance_not_asserted' => true], 201);
    }
}
