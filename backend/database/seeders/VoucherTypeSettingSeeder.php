<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\VoucherTypeSetting;
use Illuminate\Database\Seeder;

class VoucherTypeSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed only tenants that already exist. A database seeder must never
        // manufacture a simulated company just to host default settings.
        $companies = Company::query()->get();
        if ($companies->isEmpty()) {
            return;
        }

        foreach ($companies as $company) {
            foreach (VoucherTypeSetting::$SYSTEM_DEFAULTS as $voucherType => $items) {
                foreach ($items as $item) {
                    VoucherTypeSetting::firstOrCreate(
                        [
                            'company_id' => $company->id,
                            'voucher_type' => $voucherType,
                            'name' => $item['name'],
                        ],
                        [
                            'debit_account' => $item['debit_account'],
                            'credit_account' => $item['credit_account'],
                            'filter_debit' => $item['filter_debit'] ?? null,
                            'filter_credit' => $item['filter_credit'] ?? null,
                            'is_active' => true,
                            'created_by' => 1,
                        ]
                    );
                }
            }
        }
    }
}
