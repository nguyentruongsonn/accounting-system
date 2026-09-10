<?php

namespace App\Services;

use App\Exceptions\StatutoryStatementDefinitionUnavailableException;
use App\Models\StatutoryFinancialStatementDefinition;
use App\Support\FinancialReportContext;

/**
 * Read-only readiness assessment for a tenant-owned statement catalogue.
 *
 * This deliberately does not calculate a statement, derive a cash-flow,
 * render a form, sign, file, or make a legal-compliance assertion.  Its only
 * purpose is to expose the evidence that is still required before a separate
 * controlled execution implementation can even be considered.
 */
final class StatutoryFinancialStatementReadinessService
{
    public function __construct(
        private readonly AccountingRegimeService $regimes,
        private readonly StatutoryFinancialStatementDefinitionResolver $definitions,
    ) {}

    /** @return array<string,mixed> */
    public function assess(FinancialReportContext $context, string $formKey): array
    {
        $profile = $this->regimes->forFiscalYear($context->companyId, $context->fiscalYear->id);
        $asOfDate = $context->toDate;
        $definition = null;
        $conditions = [];

        try {
            $definition = $this->definitions->requirePublished(
                $context->companyId,
                $profile->id,
                $formKey,
                $asOfDate,
            );
            $conditions = $this->definitionConditions($definition);
        } catch (StatutoryStatementDefinitionUnavailableException) {
            $conditions[] = $this->missing(
                'published_effective_definition',
                'Cần đúng một catalogue đã phê duyệt và công bố, đúng doanh nghiệp, hồ sơ chế độ kế toán, biểu mẫu và ngày chốt.',
            );
        }

        // Even a complete catalogue is not an execution engine.  Keep this
        // separate from owner-supplied evidence so an API consumer cannot
        // mistake a green catalogue check for a statutory statement.
        foreach ([
            ['authoritative_ledger_extraction_and_mapping_execution', 'Chưa có engine tính chỉ tiêu từ sổ cái theo mapping đã kiểm soát.'],
            ['period_close_and_reconciliation_control_evidence', 'Chưa chứng minh được gói chốt kỳ và đối chiếu tại cùng cutoff cho lần chạy này.'],
            ['cross_foot_tie_out_and_comparative_calculation', 'Chưa có kiểm tra cộng ngang/dọc, đối chiếu và tính số so sánh cho lần chạy này.'],
            ['notes_and_cashflow_execution', 'Chưa có engine thuyết minh hoặc lưu chuyển tiền tệ được kiểm soát.'],
            ['report_run_approval_signature_retention', 'Chưa có quy trình phê duyệt, ký, lưu giữ hoặc phát hành báo cáo có thẩm quyền.'],
        ] as [$key, $reason]) {
            $conditions[] = $this->missing($key, $reason);
        }

        $definitionReady = $definition !== null && ! collect($conditions)->contains(fn (array $c): bool => in_array($c['key'], [
            'published_effective_definition', 'provenance_evidence', 'mapping_evidence', 'notes_evidence', 'comparative_evidence',
        ], true));

        return [
            'meta' => [
                'capability_version' => 'statutory-statement-readiness.v1',
                'company_id' => $context->companyId,
                'fiscal_year_id' => $context->fiscalYear->id,
                'accounting_regime_profile_id' => $profile->id,
                'accounting_regime' => $profile->regime->value,
                'form_key' => $formKey,
                'as_of_date' => $asOfDate,
                'read_only' => true,
                'statutory_output_available' => false,
                'legal_compliance_certified' => false,
                'disclaimer' => 'Đây là đánh giá điều kiện kỹ thuật/evidence, không phải báo cáo tài chính, kết quả thuế, bản ký, hồ sơ nộp hoặc xác nhận tuân thủ pháp lý.',
            ],
            'definition' => $definition === null ? null : [
                'id' => $definition->id,
                'definition_version' => $definition->definition_version,
                'contract_hash' => $definition->contract_hash,
                'effective_from' => $definition->effective_from->toDateString(),
                'effective_to' => $definition->effective_to->toDateString(),
            ],
            'definition_evidence_ready' => $definitionReady,
            'execution_ready' => false,
            'missing_conditions' => $conditions,
        ];
    }

    /** @return list<array{key:string,status:string,reason:string}> */
    private function definitionConditions(StatutoryFinancialStatementDefinition $definition): array
    {
        $checks = [
            'provenance_evidence' => [$definition->provenance_contract, ['catalogue_reference', 'source_reference', 'source_received_at']],
            'mapping_evidence' => [$definition->line_mapping_contract, ['mapping_reference', 'mapping_version', 'approved_mapping_reference']],
            'notes_evidence' => [$definition->notes_requirement_contract, ['notes_reference', 'disclosure_reference', 'requirement_reference']],
            'comparative_evidence' => [$definition->presentation_contract, ['comparative_basis', 'comparative_reference', 'restatement_policy_reference']],
        ];
        $result = [];
        foreach ($checks as $key => [$contract, $evidenceKeys]) {
            if (! $this->containsNonEmptyEvidence($contract, $evidenceKeys)) {
                $result[] = $this->missing($key, 'Catalogue đã công bố nhưng chưa có trường evidence có giá trị kiểm tra được cho điều kiện này.');
            }
        }
        return $result;
    }

    /** @param mixed $contract @param list<string> $keys */
    private function containsNonEmptyEvidence(mixed $contract, array $keys): bool
    {
        if (! is_array($contract)) return false;
        foreach ($keys as $key) {
            $value = $contract[$key] ?? null;
            if (is_string($value) && trim($value) !== '') return true;
            if (is_numeric($value)) return true;
        }
        return false;
    }

    /** @return array{key:string,status:string,reason:string} */
    private function missing(string $key, string $reason): array
    {
        return ['key' => $key, 'status' => 'missing', 'reason' => $reason];
    }
}
