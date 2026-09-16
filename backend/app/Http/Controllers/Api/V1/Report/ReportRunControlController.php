<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Models\ReportRun;
use App\Services\ReportIssuanceAuditService;
use App\Services\ReportPackageControlService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Read-only evidence inspection; this endpoint cannot approve or close a period. */
class ReportRunControlController extends Controller
{
    public function __construct(
        private readonly ReportPackageControlService $controls,
        private readonly ReportIssuanceAuditService $reportAudit,
    ) {}

    public function show(Request $request, string $uuid): JsonResponse
    {
        $run = ReportRun::withoutGlobalScope('company')
            ->where('company_id', TenantContext::companyId($request))
            ->where('uuid', $uuid)
            ->first();

        if ($run === null) {
            throw new NotFoundHttpException('Report run was not found.');
        }

        $this->reportAudit->recordControlEvidenceView($request, $run);

        $snapshot = $run->snapshot ?? [];
        $storedControls = is_array($snapshot) ? ($snapshot['package_controls'] ?? null) : null;

        return response()->json([
            'data' => [
                'schema' => 'report-run-control-evidence.v1',
                'report_run_uuid' => $run->uuid,
                'report' => $run->report,
                'issued_at' => $run->issued_at?->toIso8601String(),
                'non_certifying' => true,
                'not_a_close_gate' => true,
                // Stored controls prove what was calculated at issuance;
                // integrity controls prove the persisted snapshot was not
                // altered after that issuance.
                'stored_package_controls' => $storedControls,
                'snapshot_integrity' => $this->controls->verifyStoredRun($run),
            ],
        ]);
    }
}
