<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $company = \App\Models\Company::firstOrCreate(
            ['name' => 'CÔNG TY TNHH KẾ TOÁN MẪU'],
            [
                'tax_code' => '0101234567',
                'address' => 'Hà Nội, Việt Nam',
                'phone' => '0241234567',
                'email' => 'contact@accounting.local',
            ]
        );

        foreach ([2024, 2025, 2026, 2027] as $year) {
            \App\Models\FiscalYear::withoutGlobalScope('company')->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'year' => $year,
                ],
                [
                    'start_date' => "{$year}-01-01",
                    'end_date' => "{$year}-12-31",
                    'status' => 'open',
                ]
            );
        }

        $this->call([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
            VoucherTypeSettingSeeder::class,
            DefaultAccountingPolicySeeder::class,
            SampleBusinessDataSeeder::class,
        ]);
    }
}
