<?php

namespace Database\Seeders;

use App\Support\TwoRolePermissions;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        TwoRolePermissions::seed();
    }
}
