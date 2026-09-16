<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Period;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\TwoRolePermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Explicit thesis/demo bootstrap. This is never a production company or an
 * owner-approved TT99 catalogue. Run it only against a dedicated local/demo
 * database, then use the browser with SIM-TEST records.
 */
class AcademicSimulationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('AcademicSimulationSeeder is restricted to local/testing environments.');
        }

        DB::transaction(function (): void {
            $existingCompany = Company::query()
                ->where('name', 'SIM-ENTITY-001 — Doanh nghiệp mô phỏng')
                ->first();
            $this->assertSimulationCatalogueCodesAvailable($existingCompany?->id);

            $company = $existingCompany ?? Company::create([
                'name' => 'SIM-ENTITY-001 — Doanh nghiệp mô phỏng',
                'tax_code' => null,
                'address' => 'SIMULATED_DATA',
                'phone' => null,
                'email' => null,
            ]);

            // Keep the simulation bootstrap aligned with the production two-role
            // contract.  The seeder is local/testing-only, but it must not create
            // a role or permission shortcut that would make browser evidence
            // diverge from the real authorization boundary.
            TwoRolePermissions::seed();
            $this->ensureSimulationUser(
                companyId: $company->id,
                email: 'sim.admin@accounting.local',
                name: 'SIM-ADMIN',
                password: 'Simulate-admin-2026!',
                role: 'admin',
            );
            $this->ensureSimulationUser(
                companyId: $company->id,
                email: 'sim.operator@accounting.local',
                name: 'SIM-OPERATOR',
                password: 'Simulate-2026!',
                role: 'accountant',
            );

            $accounts = [
                ['code' => 'ACC_A', 'name' => 'Tài khoản mô phỏng A', 'type' => 'asset', 'nature' => 'debit'],
                ['code' => 'ACC_B', 'name' => 'Tài khoản mô phỏng B', 'type' => 'revenue', 'nature' => 'credit'],
                ['code' => 'ACC_C', 'name' => 'Tài khoản mô phỏng C', 'type' => 'expense', 'nature' => 'debit'],
                ['code' => '111', 'name' => 'Tiền mặt — mô phỏng học thuật', 'type' => 'asset', 'nature' => 'debit'],
                ['code' => '131', 'name' => 'Phải thu khách hàng — mô phỏng học thuật', 'type' => 'asset', 'nature' => 'debit'],
                ['code' => '156', 'name' => 'Hàng hóa — mô phỏng học thuật', 'type' => 'asset', 'nature' => 'debit'],
                ['code' => '331', 'name' => 'Phải trả người bán — mô phỏng học thuật', 'type' => 'liability', 'nature' => 'credit'],
                ['code' => '511', 'name' => 'Doanh thu bán hàng — mô phỏng học thuật', 'type' => 'revenue', 'nature' => 'credit'],
                ['code' => '632', 'name' => 'Giá vốn hàng bán — mô phỏng học thuật', 'type' => 'expense', 'nature' => 'debit'],
            ];

            foreach ($accounts as $account) {
                ChartOfAccount::updateOrCreate(
                    ['company_id' => $company->id, 'code' => $account['code']],
                    $account + [
                        'company_id' => $company->id,
                        'level' => 1,
                        'is_parent' => false,
                        'is_active' => true,
                        'description' => 'SIMULATED_DATA — academic thesis only',
                    ]
                );
            }

            Customer::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'SIM-CUSTOMER-001'],
                [
                    'company_id' => $company->id,
                    'name' => 'SIM-CUSTOMER-001',
                    'customer_type' => 'org',
                    'is_customer' => true,
                    'is_active' => true,
                    'address' => 'SIMULATED_DATA',
                ]
            );
            Customer::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'SIM-CUSTOMER-002'],
                [
                    'company_id' => $company->id,
                    'name' => 'SIM-CUSTOMER-002',
                    'customer_type' => 'org',
                    'is_customer' => true,
                    'is_active' => true,
                    'address' => 'SIMULATED_DATA',
                ]
            );

            Supplier::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'SIM-SUPPLIER-001'],
                [
                    'company_id' => $company->id,
                    'name' => 'SIM-SUPPLIER-001',
                    'supplier_type' => 'org',
                    'is_supplier' => true,
                    'is_active' => true,
                    'address' => 'SIMULATED_DATA',
                ]
            );
            Supplier::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'SIM-SUPPLIER-002'],
                [
                    'company_id' => $company->id,
                    'name' => 'SIM-SUPPLIER-002',
                    'supplier_type' => 'org',
                    'is_supplier' => true,
                    'is_active' => true,
                    'address' => 'SIMULATED_DATA',
                ]
            );

            Item::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'SIM-ITEM-001'],
                [
                    'company_id' => $company->id,
                    'type' => 'Goods',
                    'name' => 'SIM-ITEM-001 — Hàng hóa mô phỏng',
                    'unit' => 'Cái',
                    'cost_price' => 100000,
                    'selling_price' => 150000,
                    'is_active' => true,
                    'description' => 'SIMULATED_DATA — academic thesis only',
                ]
            );
            Item::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'SIM-ITEM-002'],
                [
                    'company_id' => $company->id,
                    'type' => 'Goods',
                    'name' => 'SIM-ITEM-002 — Hàng hóa mô phỏng',
                    'unit' => 'Cái',
                    'cost_price' => 200000,
                    'selling_price' => 280000,
                    'is_active' => true,
                    'description' => 'SIMULATED_DATA — academic thesis only',
                ]
            );

            Warehouse::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'SIM-WH-001'],
                [
                    'company_id' => $company->id,
                    'name' => 'SIM-WH-001 — Kho mô phỏng',
                    'default_account' => 'ACC_A',
                    'description' => 'SIMULATED_DATA — academic thesis only',
                    'is_active' => true,
                ]
            );
            Warehouse::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'SIM-WH-002'],
                [
                    'company_id' => $company->id,
                    'name' => 'SIM-WH-002 — Kho mô phỏng chi nhánh',
                    'default_account' => 'ACC_A',
                    'description' => 'SIMULATED_DATA — academic thesis only',
                    'is_active' => true,
                ]
            );

            Employee::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'SIM-EMP-001'],
                [
                    'company_id' => $company->id,
                    'name' => 'SIM-EMP-001 — Nhân viên mô phỏng',
                    'department' => 'SIMULATED_DATA',
                    'position' => 'Operator',
                    'status' => 'active',
                ]
            );

            $fiscalYear = FiscalYear::withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->id, 'year' => 2026],
                [
                    'company_id' => $company->id,
                    'year' => 2026,
                    'start_date' => '2026-01-01',
                    'end_date' => '2026-12-31',
                    'status' => 'open',
                ]
            );

            foreach (range(1, 12) as $month) {
                $start = sprintf('2026-%02d-01', $month);
                $end = date('Y-m-t', strtotime($start));

                Period::updateOrCreate(
                    ['fiscal_year_id' => $fiscalYear->id, 'period' => $month],
                    [
                        'fiscal_year_id' => $fiscalYear->id,
                        'period' => $month,
                        'period_number' => $month,
                        'name' => sprintf('Tháng %02d/2026', $month),
                        'start_date' => $start,
                        'end_date' => $end,
                        'status' => 'open',
                        'is_closed' => false,
                    ]
                );
            }

            $this->command?->info('Academic simulation tenant, 2026 periods, and master catalogue are ready: '.$company->id);
        });
    }

    private function assertSimulationCatalogueCodesAvailable(?int $companyId): void
    {
        $catalogues = [
            Customer::class => ['SIM-CUSTOMER-001', 'SIM-CUSTOMER-002'],
            Supplier::class => ['SIM-SUPPLIER-001', 'SIM-SUPPLIER-002'],
            Item::class => ['SIM-ITEM-001', 'SIM-ITEM-002'],
            Warehouse::class => ['SIM-WH-001', 'SIM-WH-002'],
        ];
        $conflicts = [];
        foreach ($catalogues as $model => $codes) {
            foreach ($codes as $code) {
                $query = $model::query()->where('code', $code);
                if ($companyId !== null) {
                    $query->where('company_id', '!=', $companyId);
                }
                if ($query->exists()) {
                    $conflicts[] = $model.'['.$code.']';
                }
            }
        }
        if ($conflicts !== []) {
            throw new RuntimeException(
                'Simulation catalogue codes already exist outside the dedicated simulation company: '
                .implode(', ', $conflicts)
                .'. Use a fresh local/demo database or resolve the existing SIMULATED_DATA records first.'
            );
        }
    }

    private function ensureSimulationUser(
        int $companyId,
        string $email,
        string $name,
        string $password,
        string $role,
    ): void {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'company_id' => $companyId,
            ],
        );

        // Never reassign an existing identity that belongs to another
        // company.  This keeps a local/demo seeder from crossing tenant
        // boundaries when it is accidentally run against a populated DB.
        if ($user->company_id !== null && (int) $user->company_id !== $companyId) {
            return;
        }
        if ($user->company_id === null) {
            $user->forceFill(['company_id' => $companyId])->save();
        }

        $user->syncRoles(Role::findByName($role, 'web'));
        // Direct grants would bypass the fixed-role contract and make the
        // simulation unlike production.  Role permissions are the only
        // source of authorization for these dedicated identities.
        $user->syncPermissions([]);
    }
}
