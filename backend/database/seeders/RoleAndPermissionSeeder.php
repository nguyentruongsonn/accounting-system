<?php

namespace Database\Seeders;

use App\Support\TwoRolePermissions;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        TwoRolePermissions::seed();
    }
}
