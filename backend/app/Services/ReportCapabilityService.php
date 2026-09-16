<?php

namespace App\Services;

use App\Support\FinancialReportContext;

/**
 * Declares the reporting surfaces that are actually available in this build.
 *
 * This is intentionally a capability declaration, not a statutory-form
 * catalogue: an available endpoint alone does not certify a report's legal
 * presentation or Appendix IV conformance.
 */
class ReportCapabilityService
{
    /**
     * @return array{meta: array<string, mixed>, capabilities: list<array<string, mixed>>}
     */
    public function manifest(FinancialReportContext $context): array
    {
        return [
            'meta' => [
                ...$context->regimeMetadata,
                'from_date' => $context->fromDate,
                'to_date' => $context->toDate,
                'manifest_version' => 'report-capabilities.v1',
                'disclaimer' => 'Trạng thái chỉ phản ánh phạm vi chức năng đang triển khai; không tự xác nhận biểu mẫu hoặc tuân thủ pháp lý.',
            ],
            'capabilities' => [
                $this->available('trial_balance', 'Bảng cân đối tài khoản', 'ledger', 'reports/trial-balance'),
                $this->available('balance_sheet', 'Bảng Cân đối kế toán (B01-DN)', 'financial_statement', 'reports/balance-sheet'),
                $this->available('income_statement', 'Báo cáo Kết quả hoạt động kinh doanh (B02-DN)', 'financial_statement', 'reports/income-statement'),
                $this->available('general_ledger', 'Sổ cái tài khoản', 'ledger', 'reports/general-ledger'),
                $this->available('general_journal', 'Sổ nhật ký chung', 'ledger', 'reports/general-journal'),
                $this->unavailable(
                    'cash_flow_statement',
                    'Báo cáo lưu chuyển tiền tệ',
                    'financial_statement',
                    'Chưa triển khai: cần mapping phiên bản biểu mẫu, quy tắc phân loại và công thức đã được phê duyệt.'
                ),
                $this->unavailable(
                    'financial_statement_notes',
                    'Thuyết minh báo cáo tài chính',
                    'financial_statement',
                    'Chưa triển khai: cần catalogue chỉ tiêu, nguồn dữ liệu và quy trình phê duyệt thuyết minh.'
                ),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function available(string $key, string $label, string $category, string $route): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'category' => $category,
            'status' => 'available',
            'implementation_status' => 'operational_draft',
            'available' => true,
            'appendix_iv_certified' => false,
            'definition_version' => null,
            'route' => $route,
            'reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(string $key, string $label, string $category, string $reason): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'category' => $category,
            'status' => 'not_implemented',
            'implementation_status' => 'not_implemented',
            'available' => false,
            'appendix_iv_certified' => false,
            'definition_version' => null,
            'route' => null,
            'reason' => $reason,
        ];
    }
}
