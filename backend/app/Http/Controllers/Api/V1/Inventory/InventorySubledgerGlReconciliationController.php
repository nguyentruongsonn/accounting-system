<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventorySubledgerGlReconciliationRun;
use App\Services\InventorySubledgerGlReconciliationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Server-controlled inventory subledger-to-GL evidence. */
final class InventorySubledgerGlReconciliationController extends Controller
{
    public function __construct(private readonly InventorySubledgerGlReconciliationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validate(['as_of_date' => ['nullable', 'date_format:Y-m-d'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $runs = InventorySubledgerGlReconciliationRun::withoutGlobalScope('company')->where('company_id', $companyId)
            ->when(isset($validated['as_of_date']), fn ($query) => $query->whereDate('as_of_date', $validated['as_of_date']))
            ->latest('id')->paginate($validated['per_page'] ?? 25);
        $runs->getCollection()->transform(fn (InventorySubledgerGlReconciliationRun $run) => $this->serialize($run));
        return response()->json($runs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['required', 'date_format:Y-m-d'], 'company_id' => ['prohibited'], 'status' => ['prohibited'],
            'amounts' => ['prohibited'], 'contract_hash' => ['prohibited'],
        ]);
        $run = $this->service->capture($request->user(), $validated['as_of_date']);
        return response()->json(['data' => $this->serialize($run)], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $run = InventorySubledgerGlReconciliationRun::withoutGlobalScope('company')
            ->where('company_id', TenantContext::companyId($request))->where('uuid', $uuid)->first();
        if (! $run) throw new NotFoundHttpException('Inventory reconciliation evidence was not found for the authenticated company.');
        return response()->json(['data' => $this->serialize($run->load('exceptions'))]);
    }

    /** @return array<string,mixed> */
    private function serialize(InventorySubledgerGlReconciliationRun $run): array
    {
        $snapshot = is_array($run->snapshot) ? $run->snapshot : [];
        $calculated = ($snapshot['amounts_calculated'] ?? false) === true;
        return [
            'uuid' => $run->uuid, 'as_of_date' => $run->as_of_date?->toDateString(), 'status' => $run->status,
            'algorithm_version' => $run->algorithm_version, 'snapshot_hash' => $run->snapshot_hash,
            'input_cutoff_at' => $snapshot['input_cutoff_at'] ?? null,
            'amounts' => $calculated ? ($snapshot['amounts'] ?? null) : null, 'amounts_calculated' => $calculated,
            'tie_out_calculated' => ($snapshot['tie_out_calculated'] ?? false) === true,
            'close_authority' => ($snapshot['close_authority'] ?? false) === true,
            'control' => $snapshot['control'] ?? null,
            'limitation' => (string) ($snapshot['statement'] ?? 'Server-controlled inventory reconciliation evidence.'),
            'exceptions' => $run->relationLoaded('exceptions') ? $run->exceptions->map(fn ($exception) => ['code' => $exception->exception_code, 'severity' => $exception->severity, 'reason' => $exception->reason]) : null,
        ];
    }
}
