<?php

namespace App\Http\Controllers\Api\V1\Bank;

use App\Http\Controllers\Concerns\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Models\BankReconciliationExceptionEvent;
use App\Models\BankReconciliationMatchEvent;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Services\BankStatementReconciliationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read and append reconciliation evidence only.  These endpoints never
 * import a file, post a voucher, create a journal entry, or close a period.
 * The compact resources deliberately omit source_payload and other raw bank
 * import material; the operational screen needs normalized evidence only.
 */
final class BankStatementReconciliationController extends Controller
{
    use HandlesApiErrors;

    public function __construct(private readonly BankStatementReconciliationService $service) {}

    public function imports(Request $request): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $items = BankStatementImport::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->with('bankAccount:id,account_number,bank_name,currency')
            ->latest('imported_at')->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json($items->through(fn (BankStatementImport $item) => $this->importResource($item)));
    }

    public function lines(Request $request, string $uuid): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $import = BankStatementImport::withoutGlobalScope('company')->where('company_id', $companyId)->where('uuid', $uuid)->firstOrFail();
        $status = $request->string('status')->toString();
        if (! in_array($status, ['', 'unmatched', 'proposed', 'confirmed', 'exception_open'], true)) {
            abort(422, 'Unsupported reconciliation status filter.');
        }

        $items = BankStatementLine::withoutGlobalScope('company')->where('company_id', $companyId)->where('bank_statement_import_id', $import->id)
            ->with(['matchEvents' => fn ($query) => $query->orderBy('id'), 'exceptionEvents' => fn ($query) => $query->orderBy('id')])
            ->orderBy('line_number')->get()->map(fn (BankStatementLine $line) => $this->lineResource($line));
        if ($status !== '') {
            $items = $items->filter(fn (array $line) => $line['reconciliation_status'] === $status)->values();
        }

        return response()->json(['data' => $items, 'meta' => ['import' => $this->importResource($import)]]);
    }

    public function timeline(Request $request, string $uuid): JsonResponse
    {
        $companyId = TenantContext::companyId($request);
        $line = BankStatementLine::withoutGlobalScope('company')->where('company_id', $companyId)->where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => [
            'line' => $this->lineResource($line->load(['matchEvents' => fn ($query) => $query->orderBy('id'), 'exceptionEvents' => fn ($query) => $query->orderBy('id')])),
            'match_events' => $line->matchEvents->map(fn (BankReconciliationMatchEvent $event) => $this->matchResource($event)),
            'exception_events' => $line->exceptionEvents->map(fn (BankReconciliationExceptionEvent $event) => $this->exceptionResource($event)),
            'read_only_boundary' => 'Reconciliation evidence only. No voucher posting, journal entry, bank balance update, or period close is performed here.',
        ]]);
    }

    public function proposeMatch(Request $request, string $uuid): JsonResponse
    {
        try {
            $data = $request->validate([
                'candidate_type' => ['required', Rule::in(['bank_receipt', 'bank_payment'])],
                'candidate_id' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:2000'],
            ]);

            return response()->json(['data' => $this->matchResource($this->service->proposeMatch($request->user(), $uuid, $data))], 201);
        } catch (\Throwable $exception) {
            return $this->apiError($request, $exception);
        }
    }

    public function decideMatch(Request $request, int $id): JsonResponse
    {
        try {
            $data = $request->validate(['decision' => ['required', Rule::in(['confirmed', 'rejected', 'reversed'])], 'reason' => ['required', 'string', 'max:2000']]);

            return response()->json(['data' => $this->matchResource($this->service->decideMatch($request->user(), $id, $data['decision'], $data['reason']))], 201);
        } catch (\Throwable $exception) {
            return $this->apiError($request, $exception);
        }
    }

    public function recordException(Request $request, string $key): JsonResponse
    {
        try {
            $data = $request->validate([
                'event_type' => ['required', Rule::in(['opened', 'resolved', 'waived', 'reopened'])],
                'line_uuid' => ['nullable', 'uuid'], 'exception_code' => ['required', 'string', 'max:60'],
                'severity' => ['required', Rule::in(['info', 'warning', 'blocking'])], 'reason' => ['nullable', 'string', 'max:2000'],
                // Evidence must be structured and small.  The read resource
                // exposes only existence, preventing a raw bank payload leak.
                'evidence' => ['nullable', 'array'],
            ]);

            return response()->json(['data' => $this->exceptionResource($this->service->recordException($request->user(), $key, $data['event_type'], $data))], 201);
        } catch (\Throwable $exception) {
            return $this->apiError($request, $exception);
        }
    }

    private function importResource(BankStatementImport $item): array
    {
        return ['uuid' => $item->uuid, 'statement_reference' => $item->statement_reference, 'source_format' => $item->source_format, 'status' => $item->status,
            'line_count' => $item->line_count, 'imported_at' => $item->imported_at?->toIso8601String(), 'imported_by' => $item->imported_by,
            'bank_account' => $item->relationLoaded('bankAccount') && $item->bankAccount ? ['id' => $item->bankAccount->id, 'account_number' => $item->bankAccount->account_number, 'bank_name' => $item->bankAccount->bank_name, 'currency' => $item->bankAccount->currency] : null];
    }

    private function lineResource(BankStatementLine $line): array
    {
        $matches = $line->relationLoaded('matchEvents') ? $line->matchEvents : collect();
        $exceptions = $line->relationLoaded('exceptionEvents') ? $line->exceptionEvents : collect();
        $latestMatch = $matches->last();
        $latestException = $exceptions->last();
        $status = $latestException && in_array($latestException->event_type, ['opened', 'reopened'], true) ? 'exception_open' : ($latestMatch?->decision ?? 'unmatched');

        return ['uuid' => $line->uuid, 'line_number' => $line->line_number, 'line_reference' => $line->line_reference,
            'booked_on' => $line->booked_on?->toDateString(), 'value_on' => $line->value_on?->toDateString(), 'direction' => $line->direction,
            'amount_raw' => $line->amount_raw, 'amount_scale' => $line->amount_scale, 'currency_code' => $line->currency_code,
            'running_balance_raw' => $line->running_balance_raw, 'running_balance_scale' => $line->running_balance_scale,
            'bank_reference' => $line->bank_reference, 'counterparty_name' => $line->counterparty_name, 'counterparty_account' => $line->counterparty_account,
            'description' => $line->description, 'reconciliation_status' => $status, 'latest_match_event_id' => $latestMatch?->id,
            'latest_exception_key' => $latestException?->exception_key, 'raw_payload_available' => false];
    }

    private function matchResource(BankReconciliationMatchEvent $event): array
    {
        return ['id' => $event->id, 'uuid' => $event->uuid, 'supersedes_event_id' => $event->supersedes_event_id, 'candidate_type' => $event->candidate_type,
            'candidate_id' => $event->candidate_id, 'decision' => $event->decision, 'amount_raw' => $event->amount_raw, 'amount_scale' => $event->amount_scale,
            'reason' => $event->reason, 'recorded_by' => $event->recorded_by, 'recorded_at' => $event->recorded_at?->toIso8601String()];
    }

    private function exceptionResource(BankReconciliationExceptionEvent $event): array
    {
        return ['uuid' => $event->uuid, 'exception_key' => $event->exception_key, 'exception_code' => $event->exception_code, 'event_type' => $event->event_type,
            'severity' => $event->severity, 'reason' => $event->reason, 'evidence_present' => $event->evidence !== null,
            'recorded_by' => $event->recorded_by, 'recorded_at' => $event->recorded_at?->toIso8601String()];
    }
}
