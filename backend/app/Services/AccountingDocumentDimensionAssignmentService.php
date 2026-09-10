<?php

namespace App\Services;

use App\Enums\SystemVoucherType;
use App\Models\AccountingDimensionDefinition;
use App\Models\AccountingDimensionValue;
use App\Models\AccountingDocumentDimensionAssignment;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persists only deliberate user selections. It never maps supplier, item,
 * warehouse, or another commercial master-data field into an accounting
 * dimension. This is evidence capture; it does not activate a posting gate.
 */
final class AccountingDocumentDimensionAssignmentService
{
    /** @var array<class-string<Model>, SystemVoucherType> */
    private const DOCUMENT_TYPES = [
        PurchaseInvoice::class => SystemVoucherType::PURCHASE_INVOICE,
        SalesInvoice::class => SystemVoucherType::SALES_INVOICE,
    ];

    public function __construct(
        private readonly AccountingPolicyResolver $policyResolver,
        private readonly AccountingDimensionValidationService $dimensionValidator,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, int|string> $dimensionValues @return \Illuminate\Support\Collection<int, AccountingDocumentDimensionAssignment> */
    public function savePurchaseInvoice(User $actor, int $invoiceId, array $dimensionValues): Collection
    {
        return DB::transaction(function () use ($actor, $invoiceId, $dimensionValues) {
            $companyId = (int) ($actor->company_id ?? 0);
            if ($companyId < 1) {
                throw new AuthorizationException('The acting user is not assigned to a company.');
            }
            $invoice = PurchaseInvoice::withoutGlobalScope('company')->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoiceId);

            return $this->save($actor, $invoice, $dimensionValues);
        });
    }

    /** @param array<string, int|string> $dimensionValues @return \Illuminate\Support\Collection<int, AccountingDocumentDimensionAssignment> */
    public function saveSalesInvoice(User $actor, int $invoiceId, array $dimensionValues): Collection
    {
        return DB::transaction(function () use ($actor, $invoiceId, $dimensionValues) {
            $companyId = (int) ($actor->company_id ?? 0);
            if ($companyId < 1) {
                throw new AuthorizationException('The acting user is not assigned to a company.');
            }
            $invoice = SalesInvoice::withoutGlobalScope('company')->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoiceId);

            return $this->save($actor, $invoice, $dimensionValues);
        });
    }

    /** @param array<string, int|string> $dimensionValues @return \Illuminate\Support\Collection<int, AccountingDocumentDimensionAssignment> */
    public function save(User $actor, Model $document, array $dimensionValues): Collection
    {
        $voucherType = self::DOCUMENT_TYPES[$document::class] ?? null;
        if ($voucherType === null) {
            throw new AuthorizationException('This accounting document type is not enabled for explicit dimension assignments.');
        }
        if ((int) $document->getAttribute('company_id') !== (int) $actor->company_id) {
            throw new AuthorizationException('The accounting document belongs to another tenant.');
        }
        if ((bool) $document->getAttribute('is_posted')) {
            throw ValidationException::withMessages(['document' => 'Posted accounting documents have immutable dimension evidence. Create a correction/reversal workflow instead.']);
        }

        $postingDate = ($document->getAttribute('accounting_date') ?? $document->getAttribute('invoice_date') ?? now())->toDateString();
        $policy = $this->policyResolver->requireForVoucher((int) $actor->company_id, $postingDate, $voucherType);
        $normalized = $this->normalizeAndValidateSelectableValues((int) $actor->company_id, $postingDate, $dimensionValues);
        // Validates required-policy completeness as well as the policy's
        // approved definition mapping. Optional values were checked above.
        $this->dimensionValidator->assertSatisfied((int) $actor->company_id, $policy['policy_id'], $postingDate, $normalized);

        $revision = ((int) AccountingDocumentDimensionAssignment::withoutGlobalScope('company')
            ->where('company_id', $actor->company_id)->where('accounting_document_type', $document::class)
            ->where('accounting_document_id', $document->getKey())->max('revision')) + 1;
        $definitions = AccountingDimensionDefinition::withoutGlobalScope('company')->where('company_id', $actor->company_id)
            ->whereIn('code', array_keys($normalized))->get()->keyBy('code');
        $values = AccountingDimensionValue::withoutGlobalScope('company')->where('company_id', $actor->company_id)
            ->whereIn('id', array_values($normalized))->get()->keyBy('id');

        $created = collect();
        foreach ($normalized as $code => $valueId) {
            $created->push(AccountingDocumentDimensionAssignment::withoutGlobalScope('company')->create([
                'company_id' => $actor->company_id, 'accounting_document_type' => $document::class, 'accounting_document_id' => $document->getKey(),
                'revision' => $revision, 'accounting_policy_version_id' => $policy['policy_id'], 'policy_contract_hash' => $policy['contract_hash'],
                'posting_date' => $postingDate, 'dimension_code' => $code,
                'accounting_dimension_definition_id' => $definitions->get($code)->id, 'accounting_dimension_value_id' => $values->get($valueId)->id,
                'selected_by' => $actor->id, 'selected_at' => now(),
                'metadata' => ['evidence_kind' => 'explicit_user_selection', 'posting_gate_enabled_by_this_save' => false],
            ]));
        }
        $this->audit->record($document, 'accounting_document.dimension_assignments_saved', [], [
            'revision' => $revision, 'policy_id' => $policy['policy_id'], 'policy_contract_hash' => $policy['contract_hash'], 'dimension_values' => $normalized,
        ], null, ['evidence_kind' => 'explicit_user_selection', 'posting_gate_enabled_by_this_save' => false]);

        return $created;
    }

    /** @return Collection<int, AccountingDocumentDimensionAssignment> */
    public function currentForPurchaseInvoice(PurchaseInvoice $invoice): Collection
    {
        $base = AccountingDocumentDimensionAssignment::withoutGlobalScope('company')->where('company_id', $invoice->company_id)
            ->where('accounting_document_type', PurchaseInvoice::class)->where('accounting_document_id', $invoice->id);
        $revision = $base->max('revision');

        return $revision === null ? collect() : $base->where('revision', $revision)->with(['definition', 'value'])->orderBy('dimension_code')->get();
    }

    /** @return Collection<int, AccountingDocumentDimensionAssignment> */
    public function currentForSalesInvoice(SalesInvoice $invoice): Collection
    {
        $base = AccountingDocumentDimensionAssignment::withoutGlobalScope('company')->where('company_id', $invoice->company_id)
            ->where('accounting_document_type', SalesInvoice::class)->where('accounting_document_id', $invoice->id);
        $revision = $base->max('revision');

        return $revision === null ? collect() : $base->where('revision', $revision)->with(['definition', 'value'])->orderBy('dimension_code')->get();
    }

    /** @param array<string, int|string> $dimensionValues @return array<string, int> */
    private function normalizeAndValidateSelectableValues(int $companyId, string $postingDate, array $dimensionValues): array
    {
        if ($dimensionValues === []) {
            throw ValidationException::withMessages(['dimension_values' => 'At least one explicit dimension selection is required.']);
        }
        $normalized = [];
        foreach ($dimensionValues as $code => $valueId) {
            if (! is_string($code) || trim($code) === '' || ! is_scalar($valueId) || ! ctype_digit((string) $valueId)) {
                throw ValidationException::withMessages(['dimension_values' => 'Dimension values must be an object keyed by non-empty dimension code with integer value IDs.']);
            }
            $normalized[trim($code)] = (int) $valueId;
        }
        if (count($normalized) !== count($dimensionValues)) {
            throw ValidationException::withMessages(['dimension_values' => 'Dimension codes must be unique.']);
        }

        $values = AccountingDimensionValue::withoutGlobalScope('company')->with('definition')->where('company_id', $companyId)->whereIn('id', array_values($normalized))->get()->keyBy('id');
        foreach ($normalized as $code => $valueId) {
            $value = $values->get($valueId);
            $definition = $value?->definition;
            if ($value === null || $definition === null || $definition->code !== $code || (int) $definition->company_id !== $companyId
                || $definition->status !== 'active' || $value->status !== 'active'
                || $postingDate < $definition->effective_from->toDateString() || $postingDate > $definition->effective_to->toDateString()
                || $postingDate < $value->effective_from->toDateString() || $postingDate > $value->effective_to->toDateString()) {
                throw ValidationException::withMessages(['dimension_values' => "Dimension [{$code}] must name an active effective value owned by this tenant definition."]);
            }
        }

        return $normalized;
    }
}
