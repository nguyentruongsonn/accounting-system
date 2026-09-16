<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApArSubledgerGlReconciliationRun;
use App\Services\ApArSubledgerGlReconciliationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Observe-only AP/AR reconciliation evidence. These endpoints never mutate
 * accounting data; a controlled result is published only by the server after
 * an approved reducer contract has calculated the tie-out.
 */
final class ApArReconciliationInputBoundaryController extends Controller
{
    public function __construct(private readonly ApArSubledgerGlReconciliationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $validated = $request->validate(['ledger' => ['nullable', 'in:ap,ar'], 'as_of_date' => ['nullable', 'date_format:Y-m-d'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $runs = ApArSubledgerGlReconciliationRun::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->when(isset($validated['ledger']), fn ($q) => $q->where('ledger', $validated['ledger']))
            ->when(isset($validated['as_of_date']), fn ($q) => $q->whereDate('as_of_date', $validated['as_of_date']))
            ->latest('id')->paginate($validated['per_page'] ?? 25);
        $runs->getCollection()->transform(fn (ApArSubledgerGlReconciliationRun $run) => $this->serialize($run));

        return response()->json($runs);
    }

    public function store(Request $request): JsonResponse
    {
        // Only the requested cutoff and ledger are inputs.  A caller cannot
        // select a company, source ID, amount, contract, status or watermark.
        $validated = $request->validate([
            'ledger' => ['required', 'in:ap,ar'],
            'as_of_date' => ['required', 'date_format:Y-m-d'],
            'company_id' => ['prohibited'], 'status' => ['prohibited'], 'amounts' => ['prohibited'],
            'input_cutoff_at' => ['prohibited'], 'input_boundary' => ['prohibited'], 'contract_hash' => ['prohibited'],
        ]);
        $run = $this->service->capture($request->user(), $validated['ledger'], $validated['as_of_date']);

        return response()->json(['data' => $this->serialize($run)], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $run = ApArSubledgerGlReconciliationRun::withoutGlobalScope('company')->where('company_id', TenantContext::companyId($request))->where('uuid', $uuid)->first();
        if (! $run) {
            throw new NotFoundHttpException('AP/AR reconciliation input-boundary evidence was not found for the authenticated company.');
        }

        return response()->json(['data' => $this->serialize($run->load('exceptions'))]);
    }

    /** @return array<string,mixed> */
    private function serialize(ApArSubledgerGlReconciliationRun $run): array
    {
        $snapshot = is_array($run->snapshot) ? $run->snapshot : [];
        $calculated = ($snapshot['amounts_calculated'] ?? false) === true;
        $tieOut = ($snapshot['tie_out_calculated'] ?? false) === true;
        $closeAuthority = ($snapshot['close_authority'] ?? false) === true;
        $limitation = $calculated
            ? (string) ($snapshot['statement'] ?? 'Server-calculated controlled AP/AR reconciliation evidence.')
            : 'Capture evidence only; it cannot calculate a balance/tie-out or authorize period close until an approved reducer contract is available.';

        return [
            'uuid' => $run->uuid, 'ledger' => $run->ledger, 'as_of_date' => $run->as_of_date?->toDateString(),
            'status' => $run->status, 'algorithm_version' => $run->algorithm_version,
            'input_cutoff_at' => $run->input_cutoff_at?->toISOString(), 'input_boundary' => $run->input_boundary,
            'snapshot_hash' => $run->snapshot_hash, 'amounts' => $calculated ? ($snapshot['amounts'] ?? null) : null,
            'amounts_calculated' => $calculated, 'tie_out_calculated' => $tieOut, 'close_authority' => $closeAuthority,
            'control' => $snapshot['control'] ?? null, 'limitation' => $limitation,
            'exceptions' => $run->relationLoaded('exceptions') ? $run->exceptions->map(fn ($exception) => ['code' => $exception->exception_code, 'severity' => $exception->severity, 'reason' => $exception->reason]) : null,
        ];
    }
}
