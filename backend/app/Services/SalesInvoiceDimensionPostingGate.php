<?php

namespace App\Services;

use App\Exceptions\AccountingDimensionUnavailableException;
use App\Models\AccountingDocumentDimensionAssignment;
use App\Models\SalesInvoice;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Revalidates explicit sales dimensions while the source invoice is locked. */
final class SalesInvoiceDimensionPostingGate
{
    public function __construct(private readonly AccountingDimensionValidationService $validator) {}

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    public function requireSatisfied(SalesInvoice $invoice, array $policy): array
    {
        $postingDate = ($invoice->accounting_date ?? $invoice->invoice_date ?? now())->toDateString();
        $required = $policy['required_dimensions'] ?? null;
        if (! is_array($required)) throw ValidationException::withMessages(['dimensions' => 'Sales posting requires an explicit approved policy dimension declaration.']);
        if ($required === []) return ['gate' => 'enforced', 'status' => 'not_required', 'policy_id' => (int) $policy['policy_id'], 'policy_contract_hash' => (string) $policy['contract_hash'], 'posting_date' => $postingDate, 'required_dimensions' => [], 'assignment_revision' => null, 'dimension_values' => [], 'dimension_evidence_hash' => self::evidenceHash([])];

        $evidence = $this->latestEvidenceForLockedInvoice($invoice);
        if ($evidence->isEmpty()) throw ValidationException::withMessages(['dimensions' => 'Sales posting requires a complete explicit dimension-assignment snapshot.']);
        $first = $evidence->first();
        foreach ($evidence as $assignment) {
            if ((int) $assignment->company_id !== (int) $invoice->company_id || $assignment->accounting_document_type !== SalesInvoice::class
                || (int) $assignment->accounting_document_id !== (int) $invoice->id || (int) $assignment->revision !== (int) $first->revision
                || (int) $assignment->accounting_policy_version_id !== (int) $policy['policy_id'] || ! hash_equals((string) $policy['contract_hash'], (string) $assignment->policy_contract_hash)
                || $assignment->posting_date->toDateString() !== $postingDate) {
                throw ValidationException::withMessages(['dimensions' => 'The latest dimension snapshot does not match the effective sales posting policy and posting date.']);
            }
            if ($assignment->definition === null || $assignment->value === null || (int) $assignment->definition->company_id !== (int) $invoice->company_id
                || (int) $assignment->value->company_id !== (int) $invoice->company_id || (int) $assignment->value->accounting_dimension_definition_id !== (int) $assignment->definition->id
                || $assignment->definition->code !== $assignment->dimension_code) {
                throw ValidationException::withMessages(['dimensions' => 'The latest dimension snapshot contains cross-tenant or inconsistent dimension evidence.']);
            }
        }
        try {
            $this->validator->assertSatisfied((int) $invoice->company_id, (int) $policy['policy_id'], $postingDate, $evidence->pluck('accounting_dimension_value_id', 'dimension_code')->map(fn ($id): int => (int) $id)->all());
        } catch (AccountingDimensionUnavailableException $exception) {
            throw ValidationException::withMessages(['dimensions' => $exception->getMessage()]);
        }
        $lineage = $evidence->sortBy('dimension_code')->map(fn (AccountingDocumentDimensionAssignment $assignment): array => [
            'dimension_code' => $assignment->dimension_code, 'dimension_definition_id' => (int) $assignment->accounting_dimension_definition_id,
            'dimension_value_id' => (int) $assignment->accounting_dimension_value_id, 'selected_by' => (int) $assignment->selected_by,
            'selected_at' => $assignment->selected_at?->toAtomString(),
        ])->values()->all();
        return ['gate' => 'enforced', 'status' => 'satisfied', 'policy_id' => (int) $policy['policy_id'], 'policy_contract_hash' => (string) $policy['contract_hash'], 'posting_date' => $postingDate, 'required_dimensions' => array_values($required), 'assignment_revision' => (int) $first->revision, 'dimension_values' => $lineage, 'dimension_evidence_hash' => self::evidenceHash($lineage)];
    }

    /** @return Collection<int,AccountingDocumentDimensionAssignment> */
    private function latestEvidenceForLockedInvoice(SalesInvoice $invoice): Collection
    {
        $base = AccountingDocumentDimensionAssignment::withoutGlobalScope('company')->where('company_id', $invoice->company_id)->where('accounting_document_type', SalesInvoice::class)->where('accounting_document_id', $invoice->id);
        $revision = $base->lockForUpdate()->max('revision');
        return $revision === null ? collect() : $base->where('revision', $revision)->with(['definition', 'value'])->lockForUpdate()->orderBy('dimension_code')->get();
    }

    /** @param array<int,array<string,mixed>> $lineage */
    private static function evidenceHash(array $lineage): string { return hash('sha256', json_encode($lineage, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
}
