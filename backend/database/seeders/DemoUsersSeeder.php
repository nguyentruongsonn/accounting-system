<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Creates explicitly configured local/demo accounts. This seeder is never
 * part of the production bootstrap and never contains a password literal.
 */
final class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoUsersSeeder is restricted to local/testing environments.');
        }

        $adminPassword = trim((string) env('DEMO_ADMIN_PASSWORD', ''));
        $accountantPassword = trim((string) env('DEMO_ACCOUNTANT_PASSWORD', ''));

        if ($adminPassword === '' || $accountantPassword === '') {
            throw new RuntimeException('Set DEMO_ADMIN_PASSWORD and DEMO_ACCOUNTANT_PASSWORD before running DemoUsersSeeder.');
        }

        $company = Company::query()
            ->where('name', 'CÔNG TY TNHH KẾ TOÁN MẪU')
            ->firstOrFail();

        $this->ensureUser(
            companyId: (int) $company->id,
            email: 'admin@accounting.local',
            name: 'Quản trị viên',
            password: $adminPassword,
            role: 'admin',
        );
        $this->ensureUser(
            companyId: (int) $company->id,
            email: 'accountant@accounting.local',
            name: 'Kế toán viên',
            password: $accountantPassword,
            role: 'accountant',
        );
    }

    private function ensureUser(int $companyId, string $email, string $name, string $password, string $role): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'company_id' => $companyId,
                'is_active' => true,
            ],
        );

        $user->syncRoles([$role]);
    }
}
