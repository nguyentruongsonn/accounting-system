<?php

namespace App\Models;

use App\Enums\AccountingRegime;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class AccountingRegimeProfile extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'regime',
        'effective_from',
        'effective_to',
        'legal_source',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'regime' => AccountingRegime::class,
        'effective_from' => 'date',
        'effective_to' => 'date',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            $fiscalYear = FiscalYear::withoutGlobalScope('company')
                ->where('company_id', $profile->company_id)
                ->find($profile->fiscal_year_id);

            if ($fiscalYear === null) {
                throw ValidationException::withMessages([
                    'fiscal_year_id' => 'Năm tài chính phải thuộc doanh nghiệp của hồ sơ chế độ kế toán.',
                ]);
            }

            $rawRegime = $profile->getAttributes()['regime'] ?? null;
            $regime = $rawRegime instanceof AccountingRegime
                ? $rawRegime
                : AccountingRegime::tryFrom(strtoupper((string) $rawRegime));
            $expected = AccountingRegime::forFiscalYearStart($fiscalYear->start_date->toDateString());

            if ($regime === null || $regime !== $expected) {
                throw ValidationException::withMessages([
                    'regime' => "Năm tài chính bắt đầu {$fiscalYear->start_date->toDateString()} phải áp dụng {$expected->value}.",
                ]);
            }

            $effectiveFrom = $profile->effective_from?->toDateString()
                ?? (string) $profile->getRawOriginal('effective_from');
            $effectiveTo = $profile->effective_to?->toDateString()
                ?? (string) $profile->getRawOriginal('effective_to');

            if ($effectiveFrom !== $fiscalYear->start_date->toDateString()
                || $effectiveTo !== $fiscalYear->end_date->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Hiệu lực hồ sơ chế độ kế toán phải trùng phạm vi năm tài chính.',
                ]);
            }

            $profile->regime = $expected;
            $profile->legal_source = $expected->label();
        });
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
