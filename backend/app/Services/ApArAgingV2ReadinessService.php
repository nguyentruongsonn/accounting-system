<?php

namespace App\Services;

use App\Exceptions\ReportDefinitionUnavailableException;
use Illuminate\Support\Facades\Schema;

/**
 * Proves the prerequisites for publishing an AP/AR aging v2 result.
 *
 * This is deliberately stricter than a schema-exists check. A frozen
 * as-of-date parameter is not, by itself, immutable cutoff evidence, and the
 * current GL reconciliation shadow explicitly is not an AP/AR subledger to
 * control-account reconciliation. Until both exist, a signed definition must
 * remain non-executable.
 */
final class ApArAgingV2ReadinessService
{
    /** @return array{ready:bool,ledger:string,report_key:string,missing_conditions:list<array{code:string,reason:string}>} */
    public function evaluate(string $ledger): array
    {
        if (! in_array($ledger, ['ap', 'ar'], true)) {
            throw new \InvalidArgumentException('AP/AR ledger must be ap or ar.');
        }

        $source = AllocationAwareAgingV2Service::supportedSourceContract($ledger);
        $reportKey = $ledger === 'ap' ? 'accounts_payable_aging.v2' : 'accounts_receivable_aging.v2';
        $missing = [];

        $allocationColumns = [
            'company_id', 'source_document_type', 'source_document_id', 'source_line_type', 'source_line_id',
            'target_document_type', 'target_document_id', 'amount_raw', 'amount_scale', 'allocation_direction',
            'effective_date', 'status',
        ];
        if (! Schema::hasTable('settlement_allocations')
            || ! Schema::hasColumns('settlement_allocations', $allocationColumns)) {
            $missing[] = [
                'code' => 'canonical_settlement_allocation_evidence_unavailable',
                'reason' => 'The immutable, typed allocation table or its required company/source/target/amount/direction/cutoff fields are unavailable.',
            ];
        }

        $adjustments = $source['adjustment_completeness'] ?? null;
        if (! is_array($adjustments) || ($adjustments['status'] ?? null) !== 'available') {
            $missing[] = [
                'code' => 'typed_adjustment_source_contract_not_owner_approved',
                'reason' => 'Returns, discounts, credit notes, write-offs, reversals and FX treatment do not yet have one owner-approved, complete AP/AR aging reducer contract.',
            ];
        }

        // Revaluation rows exist, but the aging reducer does not yet prove
        // that every foreign open item has a complete carrying/settlement FX
        // roll-forward at the requested cutoff.
        if (! Schema::hasTable('ap_ar_fx_revaluations')
            || ! Schema::hasColumns('ap_ar_fx_revaluations', [
                'company_id', 'ledger', 'reference_document_type', 'reference_document_id',
                'accounting_date', 'adjustment_functional_amount', 'effect', 'status',
            ])) {
            $missing[] = [
                'code' => 'fx_revaluation_evidence_unavailable',
                'reason' => 'Typed posted AP/AR FX revaluation evidence is unavailable.',
            ];
        } else {
            $missing[] = [
                'code' => 'foreign_currency_open_item_roll_forward_not_integrated',
                'reason' => 'The exact foreign-currency roll-forward is not integrated into the published aging reducer for every foreign open item.',
            ];
        }

        // A request as_of_date controls filtering, but an immutable cutoff
        // snapshot/run with source watermark is not implemented. A later
        // back-dated posting could otherwise change a historical rerun.
        $missing[] = [
            'code' => 'immutable_cutoff_snapshot_evidence_not_implemented',
            'reason' => 'No append-only AP/AR aging run records the cutoff, definition hash and source watermark needed to prove a historical rerun.',
        ];

        // An append-only capability snapshot now records this dependency, but
        // it deliberately does not calculate balances until Finance approves
        // the complete open-item reducer and effective-dated 131/331 mapping.
        // Therefore it remains a blocking condition for aging execution.
        $missing[] = [
            'code' => 'apar_subledger_to_gl_reconciliation_not_executable',
            'reason' => 'The append-only AP/AR-to-GL reconciliation foundation records readiness evidence, but no owner-approved open-item reducer or effective-dated 131/331 mapping yet proves a same-cutoff tie-out.',
        ];

        return [
            'ready' => $missing === [],
            'ledger' => $ledger,
            'report_key' => $reportKey,
            'missing_conditions' => $missing,
        ];
    }

    /** @throws ReportDefinitionUnavailableException */
    public function assertExecutable(string $ledger): void
    {
        $readiness = $this->evaluate($ledger);
        if (! $readiness['ready']) {
            throw new ReportDefinitionUnavailableException($readiness['report_key']);
        }
    }
}
