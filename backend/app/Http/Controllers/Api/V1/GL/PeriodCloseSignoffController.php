<?php

namespace App\Http\Controllers\Api\V1\GL;

use App\Http\Controllers\Controller;
use App\Models\PeriodCloseSignoffPackage;
use App\Services\PeriodCloseSignoffService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read/write evidence workbench. These endpoints never close a period. */
class PeriodCloseSignoffController extends Controller
{
    public function __construct(private readonly PeriodCloseSignoffService $service) {}

    public function index(Request $request, int $periodId): JsonResponse
    {
        $packages = PeriodCloseSignoffPackage::withoutGlobalScope('company')->where('company_id', TenantContext::companyId($request))->where('period_id', $periodId)->with('events')->latest('id')->get();

        return response()->json(['data' => $packages->map(fn (PeriodCloseSignoffPackage $package) => $this->serialize($package))]);
    }

    public function store(Request $request, int $periodId): JsonResponse
    {
        $validated = $request->validate(['readiness_snapshot_id' => ['required', 'integer']]);
        $package = $this->service->prepare($request->user(), $periodId, (int) $validated['readiness_snapshot_id']);

        return response()->json(['data' => $this->serialize($package)], 201);
    }

    public function submit(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['evidence' => ['nullable', 'array']]);
        $package = $this->package($request, $uuid);

        return response()->json(['data' => $this->serialize($this->service->submit($request->user(), $package, $validated['evidence'] ?? null))]);
    }

    public function decide(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['step_order' => ['required', 'integer', 'min:1'], 'decision' => ['required', 'in:approved,rejected'], 'evidence' => ['nullable', 'array']]);
        $package = $this->package($request, $uuid);

        return response()->json(['data' => $this->serialize($this->service->decide($request->user(), $package, (int) $validated['step_order'], $validated['decision'], $validated['evidence'] ?? null))]);
    }

    private function package(Request $request, string $uuid): PeriodCloseSignoffPackage
    {
        return PeriodCloseSignoffPackage::withoutGlobalScope('company')->where('company_id', TenantContext::companyId($request))->where('uuid', $uuid)->with('events')->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function serialize(PeriodCloseSignoffPackage $package): array
    {
        return ['uuid' => $package->uuid, 'period_id' => $package->period_id, 'readiness_snapshot_id' => $package->period_close_readiness_snapshot_id, 'readiness_snapshot_hash' => $package->readiness_snapshot_hash, 'evidence_cutoff_at' => $package->evidence_cutoff_at?->toISOString(), 'package_hash' => $package->package_hash, 'state' => $this->service->state($package), 'close_permitted_by_this_package' => false, 'limitation' => 'This is review evidence only. It cannot authorize period close.', 'policy_snapshot' => $package->policy_snapshot, 'events' => $package->events->map(fn ($event) => ['event_type' => $event->event_type, 'approval_request_id' => $event->approval_request_id, 'event_hash' => $event->event_hash, 'recorded_by' => $event->recorded_by, 'recorded_at' => $event->recorded_at?->toISOString()]), 'prepared_by' => $package->prepared_by, 'prepared_at' => $package->prepared_at?->toISOString()];
    }
}
