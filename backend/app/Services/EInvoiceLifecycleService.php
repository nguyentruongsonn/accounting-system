<?php

namespace App\Services;

use App\Models\EInvoiceDocument;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EInvoiceLifecycleService
{
    private const ACCOUNTING_TYPES = [SalesInvoice::class, PurchaseInvoice::class];
    private const TRANSITIONS = [
        'draft' => ['issued', 'cancelled'],
        'issued' => ['replaced', 'adjusted', 'cancelled'],
        'replaced' => ['cancelled'],
        'adjusted' => ['adjusted', 'replaced', 'cancelled'],
        'cancelled' => [],
    ];

    public function record(int $companyId, int $actorId, array $data): EInvoiceDocument
    {
        return DB::transaction(function () use ($companyId, $actorId, $data): EInvoiceDocument {
            $status = (string) ($data['lifecycle_status'] ?? '');
            if (! in_array($status, EInvoiceDocument::STATUSES, true)) {
                throw ValidationException::withMessages(['lifecycle_status' => 'Unsupported e-invoice lifecycle status.']);
            }
            $document = $this->accountingDocument($companyId, (string) ($data['accounting_document_type'] ?? ''), (int) ($data['accounting_document_id'] ?? 0));
            $previous = null;
            if (! empty($data['supersedes_einvoice_document_id'])) {
                $previous = EInvoiceDocument::withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->findOrFail((int) $data['supersedes_einvoice_document_id']);
                if ((int) $previous->company_id !== $companyId || $previous->accounting_document_type !== $document::class || (int) $previous->accounting_document_id !== (int) $document->getKey()) {
                    throw new AuthorizationException('The lifecycle reference is not in the same tenant accounting document.');
                }
                if (! in_array($status, self::TRANSITIONS[$previous->lifecycle_status] ?? [], true)) {
                    throw ValidationException::withMessages(['lifecycle_status' => "Invalid transition from {$previous->lifecycle_status} to {$status}."]);
                }
            } elseif ($status !== 'draft') {
                throw ValidationException::withMessages(['supersedes_einvoice_document_id' => 'A non-draft lifecycle record must reference its preceding evidence record.']);
            }

            // Issuance/replacement/adjustment is a controlled hand-off. A user
            // cannot record it against a source invoice they created.
            if (in_array($status, ['issued', 'replaced', 'adjusted', 'cancelled'], true) && $document->getAttribute('created_by') !== null && (int) $document->getAttribute('created_by') === $actorId) {
                throw new AuthorizationException('Separation of duties forbids the accounting-document maker from recording this e-invoice lifecycle event.');
            }
            $snapshot = Arr::get($data, 'payload_snapshot');
            $hash = hash('sha256', json_encode($snapshot ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $record = EInvoiceDocument::withoutGlobalScope('company')->create([
                'company_id' => $companyId,
                'accounting_document_type' => $document::class,
                'accounting_document_id' => $document->getKey(),
                'lifecycle_status' => $status,
                'provider_name' => $data['provider_name'] ?? null,
                'provider_document_id' => $data['provider_document_id'] ?? null,
                'document_reference' => $data['document_reference'] ?? null,
                'payload_hash' => $hash,
                'payload_snapshot' => $snapshot,
                'supersedes_einvoice_document_id' => $previous?->id,
                'recorded_by' => $actorId,
                'occurred_at' => $data['occurred_at'] ?? now(),
                'metadata' => ['boundary' => 'provider-evidence-only', 'legal_compliance_not_asserted' => true],
            ]);
            app(AuditService::class)->record($record, 'einvoice.lifecycle_recorded', [], $record->only(['lifecycle_status', 'payload_hash', 'document_reference']), null, ['accounting_document_type' => $document::class, 'accounting_document_id' => $document->getKey(), 'legal_compliance_not_asserted' => true]);
            return $record;
        });
    }

    private function accountingDocument(int $companyId, string $type, int $id): Model
    {
        if (! in_array($type, self::ACCOUNTING_TYPES, true) || $id < 1) throw new AuthorizationException('Unsupported accounting document link.');
        // Scope before locking/loading so a foreign accounting document is
        // never selected into the transaction merely to reject it later.
        // Preserve the service's explicit cross-tenant error contract with a
        // non-locking existence probe; the foreign row is never hydrated.
        if ($type::withoutGlobalScope('company')
            ->whereKey($id)
            ->where('company_id', '!=', $companyId)
            ->exists()) {
            throw new AuthorizationException('Accounting document belongs to another tenant.');
        }
        $record = $type::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->findOrFail($id);
        if ((int) $record->company_id !== $companyId) throw new AuthorizationException('Accounting document belongs to another tenant.');
        return $record;
    }
}
