<?php

namespace App\Models;

use App\Enums\AccountingRegime;
use App\Models\Traits\BelongsToCompany;
use App\Services\AccountingRegimeService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class FiscalYear extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'year',
        'start_date',
        'end_date',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $fiscalYear): void {
            if (! Schema::hasTable('accounting_regime_profiles')) {
                return;
            }

            // A fiscal-year date is not owner/legal evidence for an accounting
            // regime.  Production must remain fail-closed until an explicit
            // tenant/fiscal-year profile is supplied; otherwise this hook
            // would silently turn a TT99/TT200 date heuristic into an active
            // profile during onboarding.
            if (in_array(strtolower((string) config('app.env', 'production')), ['production', 'prod'], true)) {
                return;
            }

            $profile = $fiscalYear->accountingRegimeProfile()
                ->withoutGlobalScope('company')
                ->first();

            if ($profile === null) {
                app(AccountingRegimeService::class)->ensureProfile($fiscalYear);

                return;
            }

            $expected = AccountingRegime::forFiscalYearStart($fiscalYear->start_date->toDateString());
            $profile->update([
                'company_id' => $fiscalYear->company_id,
                'regime' => $expected->value,
                'effective_from' => $fiscalYear->start_date->toDateString(),
                'effective_to' => $fiscalYear->end_date->toDateString(),
                'legal_source' => $expected->label(),
            ]);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function accountingRegimeProfile(): HasOne
    {
        return $this->hasOne(AccountingRegimeProfile::class);
    }
}
