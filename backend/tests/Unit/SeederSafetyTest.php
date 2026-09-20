<?php

namespace Tests\Unit;

use Tests\TestCase;

final class SeederSafetyTest extends TestCase
{
    public function test_database_seeder_contains_no_fixed_demo_credentials(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        self::assertIsString($source);
        self::assertStringNotContainsString('admin@accounting.local', $source);
        self::assertStringNotContainsString('admin@admin.com', $source);
        self::assertStringNotContainsString("Hash::make('password')", $source);
    }

    public function test_academic_simulation_seeder_requires_passwords_from_environment(): void
    {
        $source = file_get_contents(database_path('seeders/AcademicSimulationSeeder.php'));

        self::assertIsString($source);
        self::assertStringContainsString('SIMULATION_ADMIN_PASSWORD', $source);
        self::assertStringContainsString('SIMULATION_ACCOUNTANT_PASSWORD', $source);
        self::assertStringNotContainsString('Simulate-admin-2026!', $source);
        self::assertStringNotContainsString('Simulate-2026!', $source);
    }
}
