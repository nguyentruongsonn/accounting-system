<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\JournalEntry;
use App\Models\PeriodCloseSignoffPackage;
use App\Models\PurchaseInvoice;
use App\Models\ReconciliationRun;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Read-only forensic projection. It deliberately returns evidence references
 * and safe summaries, never original request payloads, IPs, agents or secrets.
 */
final class AccountingAuditTrailExplorer
{
    /** @var array<string, class-string<Model>> */
    private const ENTITIES = [
        'purchase_invoice' => PurchaseInvoice::class,
        'sales_invoice' => SalesInvoice::class,
        'cash_receipt' => CashReceipt::class,
        'cash_payment' => CashPayment::class,
        'bank_receipt' => BankReceipt::class,
        'bank_payment' => BankPayment::class,
        'journal_entry' => JournalEntry::class,
        'reconciliation_run' => ReconciliationRun::class,
        'period_close_signoff_package' => PeriodCloseSignoffPackage::class,
    ];

    /** @return array<string, class-string<Model>> */
    public function entityTypes(): array
    {
        return self::ENTITIES;
    }

    /** @return array<string, mixed> */
    public function trace(int $companyId, string $entityType, string $entityId): array
    {
        $companyId = $this->companyId($companyId);
        $class = self::ENTITIES[$entityType] ?? null;
        abort_unless($class !== null, 422, 'Unsupported audit-trail entity type.');

        $entity = $this->findEntity($class, $companyId, $entityId);
        $morph = $entity->getMorphClass();
        $entityAudits = AuditLog::withoutGlobalScope('company')
            ->where('company_id', $companyId)->where('model_type', $morph)->where('model_id', $entity->getKey())
            ->orderBy('id')->get();

        $journalIds = collect([(int) ($entity->getAttribute('journal_entry_id') ?? 0)])
            ->filter()
            ->merge(JournalEntry::withoutGlobalScope('company')->where('company_id', $companyId)
                ->where('source_document_type', $morph)->where('source_document_id', $entity->getKey())->pluck('id'))
            ->unique()->values();
        $correlationIds = $entityAudits->pluck('correlation_id')->filter()->unique()->take(25)->values();

        $relatedAudits = AuditLog::withoutGlobalScope('company')->where('company_id', $companyId)
            ->where(function ($query) use ($journalIds, $correlationIds): void {
                if ($journalIds->isNotEmpty()) {
                    $query->where(function ($q) use ($journalIds): void {
                        $q->where('model_type', (new JournalEntry)->getMorphClass())->whereIn('model_id', $journalIds);
                    });
                }
                if ($correlationIds->isNotEmpty()) {
                    $method = $journalIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('correlation_id', $correlationIds);
                }
            })->orderBy('id')->limit(250)->get();

        $allAudits = $entityAudits->concat($relatedAudits)->unique('id')->sortBy('id')->values();
        $approvalRequests = ApprovalRequest::withoutGlobalScope('company')->where('company_id', $companyId)
            ->where('subject_type', $morph)->where('subject_id', $entity->getKey())
            ->orderBy('id')->get(['id', 'approval_key', 'status', 'requested_by', 'requested_at', 'resolved_by', 'resolved_at']);

        return [
            'read_only' => true,
            'tenant_scope' => 'authenticated_user_company',
            'entity' => $this->entitySummary($entityType, $entity),
            'journal_entries' => JournalEntry::withoutGlobalScope('company')->where('company_id', $companyId)
                ->whereIn('id', $journalIds)->with('lines:id,journal_entry_id,account_code,debit_amount,credit_amount')
                ->get()->map(fn (JournalEntry $entry) => $this->journalSummary($entry))->values(),
            'approval_requests' => $approvalRequests->map(fn (ApprovalRequest $request) => [
                'id' => $request->id, 'approval_key' => $request->approval_key, 'status' => $request->status,
                'requested_by' => $request->requested_by, 'requested_at' => optional($request->requested_at)->toIso8601String(),
                'resolved_by' => $request->resolved_by, 'resolved_at' => optional($request->resolved_at)->toIso8601String(),
            ])->values(),
            'events' => $allAudits->map(fn (AuditLog $log) => $this->auditSummary($log))->values(),
            'linkage_note' => 'Only explicit source-document, journal-entry, and correlation-id links are shown. No inferred reconciliation or close relationship is asserted.',
        ];
    }

    /** @return array<string, mixed> */
    public function search(int $companyId, array $filters): array
    {
        $companyId = $this->companyId($companyId);
        $query = AuditLog::withoutGlobalScope('company')->where('company_id', $companyId)->orderByDesc('id');
        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (! empty($filters['correlation_id'])) {
            $query->where('correlation_id', $filters['correlation_id']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }
        if (! empty($filters['entity_type'])) {
            $class = self::ENTITIES[$filters['entity_type']] ?? null;
            abort_unless($class !== null, 422, 'Unsupported audit-trail entity type.');
            $query->where('model_type', (new $class)->getMorphClass());
        }
        if (! empty($filters['entity_id'])) {
            $query->where('model_id', $filters['entity_id']);
        }
        $page = $query->paginate($filters['per_page'] ?? 50);

        return ['data' => collect($page->items())->map(fn (AuditLog $log) => $this->auditSummary($log))->values(), 'meta' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]];
    }

    private function findEntity(string $class, int $companyId, string $entityId): Model
    {
        $key = ($class === PeriodCloseSignoffPackage::class || $class === ReconciliationRun::class) ? 'uuid' : 'id';

        return $class::withoutGlobalScope('company')->where('company_id', $companyId)->where($key, $entityId)->firstOrFail();
    }

    private function companyId(int $companyId): int
    {
        if ($companyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => 'An explicit company context is required for audit-trail access.',
            ]);
        }

        $actorCompanyId = auth()->user()?->company_id;
        if ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }

    /** @return array<string, mixed> */
    private function entitySummary(string $type, Model $entity): array
    {
        $attributes = $entity->getAttributes();

        return array_filter([
            'type' => $type, 'id' => (string) ($attributes['uuid'] ?? $entity->getKey()),
            'status' => $attributes['status'] ?? null, 'voucher_number' => $attributes['voucher_number'] ?? $attributes['invoice_number'] ?? null,
            'voucher_date' => $attributes['voucher_date'] ?? $attributes['accounting_date'] ?? null,
            'journal_entry_id' => $attributes['journal_entry_id'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function journalSummary(JournalEntry $entry): array
    {
        return ['id' => $entry->id, 'voucher_number' => $entry->voucher_number, 'voucher_date' => optional($entry->voucher_date)->toDateString(),
            'posting_date' => optional($entry->posting_date)->toDateString(), 'status' => $entry->status, 'total_amount' => $entry->total_amount,
            'lines' => $entry->lines->map(fn ($line) => ['account_code' => $line->account_code, 'debit_amount' => $line->debit_amount, 'credit_amount' => $line->credit_amount])->values()];
    }

    /** @return array<string, mixed> */
    private function auditSummary(AuditLog $log): array
    {
        return ['id' => $log->id, 'occurred_at' => optional($log->created_at)->toIso8601String(), 'action' => $log->action,
            'entity_type' => $log->model_type, 'entity_id' => (string) $log->model_id, 'actor_id' => $log->user_id,
            'correlation_id' => $log->correlation_id, 'metadata' => $this->safeMetadata($log->metadata ?? [])];
    }

    /** @return array<string, mixed> */
    private function safeMetadata(array $metadata): array
    {
        $blocked = '/(secret|password|token|api[_-]?key|authorization|payload|attached|content|signature|private[_-]?key|user[_-]?agent|ip)/i';
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (preg_match($blocked, (string) $key)) {
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = $this->safeMetadata($value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 255) : $value;
            }
        }

        return $safe;
    }
}
