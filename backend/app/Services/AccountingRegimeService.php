<?php

namespace App\Services;

use App\Enums\AccountingRegime;
use App\Models\AccountingRegimeProfile;
use App\Models\FiscalYear;
use Illuminate\Validation\ValidationException;

class AccountingRegimeService
{
    public function forFiscalYear(int $companyId, int $fiscalYearId): AccountingRegimeProfile
    {
        $companyId = $this->companyId($companyId);
        $fiscalYear = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->find($fiscalYearId);

        if ($fiscalYear === null) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => 'Năm tài chính không thuộc doanh nghiệp hiện tại.',
            ]);
        }

        return $this->resolveProfile($fiscalYear);
    }

    public function forDate(int $companyId, string $date): AccountingRegimeProfile
    {
        $companyId = $this->companyId($companyId);
        $fiscalYear = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderByDesc('id')
            ->first();

        if ($fiscalYear === null) {
            throw ValidationException::withMessages([
                'report_date' => 'Ngày báo cáo không thuộc năm tài chính nào của doanh nghiệp.',
            ]);
        }

        return $this->resolveProfile($fiscalYear);
    }

    public function reportMetadata(
        int $companyId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?int $fiscalYearId = null
    ): array {
        if ($fiscalYearId !== null) {
            return $this->metadata($this->forFiscalYear($companyId, $fiscalYearId));
        }

        $profile = $this->forDate($companyId, $toDate ?? $fromDate ?? now()->toDateString());

        return $this->metadata($profile);
    }

    public function ensureProfile(FiscalYear $fiscalYear): AccountingRegimeProfile
    {
        $expected = AccountingRegime::forFiscalYearStart($fiscalYear->start_date->toDateString());

        return AccountingRegimeProfile::withoutGlobalScope('company')->firstOrCreate(
            ['fiscal_year_id' => $fiscalYear->id],
            [
                'company_id' => $fiscalYear->company_id,
                'regime' => $expected->value,
                'effective_from' => $fiscalYear->start_date->toDateString(),
                'effective_to' => $fiscalYear->end_date->toDateString(),
                'legal_source' => $expected->label(),
            ]
        );
    }

    private function resolveProfile(FiscalYear $fiscalYear): AccountingRegimeProfile
    {
        $profile = AccountingRegimeProfile::withoutGlobalScope('company')
            ->where('company_id', $fiscalYear->company_id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->first();

        if ($profile !== null) {
            return $profile;
        }

        if (in_array(strtolower((string) config('app.env', 'production')), ['production', 'prod'], true)) {
            throw ValidationException::withMessages([
                'accounting_regime_profile' => 'Hồ sơ chế độ kế toán phải được cung cấp rõ ràng trước khi dùng trong production; hệ thống không tự chọn theo ngày.',
            ]);
        }

        return $this->ensureProfile($fiscalYear);
    }

    private function metadata(AccountingRegimeProfile $profile): array
    {
        return [
            'company_id' => (int) $profile->company_id,
            'fiscal_year_id' => (int) $profile->fiscal_year_id,
            'accounting_regime' => $profile->regime->value,
            'accounting_regime_label' => $profile->regime->label(),
            'effective_from' => $profile->effective_from->toDateString(),
            'effective_to' => $profile->effective_to->toDateString(),
        ];
    }

    private function companyId(int $companyId): int
    {
        if ($companyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => 'An explicit company context is required for accounting regime resolution.',
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
}
