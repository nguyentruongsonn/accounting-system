<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DataIsolationAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_operational_data_returns_success_and_json_summary(): void
    {
        DB::table('customers')->insert([
            'company_id' => 1,
            'code' => 'CUS-001',
            'name' => 'Công ty khách hàng thật',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withoutMockingConsoleOutput();
        $this->assertSame(0, $this->artisan('data:audit-isolation', ['--json' => true]));

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('clean', $payload['status']);
        $this->assertSame([], $payload['matches']);
        $this->assertGreaterThanOrEqual(1, $payload['scanned_rows']);
    }

    public function test_simulation_markers_fail_closed_and_identify_source_rows(): void
    {
        $id = DB::table('customers')->insertGetId([
            'company_id' => 1,
            'code' => 'SIM-TEST-CUST-001',
            'name' => 'Khách hàng mô phỏng',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withoutMockingConsoleOutput();
        $this->assertSame(1, $this->artisan('data:audit-isolation', ['--json' => true]));

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('review_required', $payload['status']);
        $this->assertCount(1, $payload['matches']);
        $this->assertSame('customers', $payload['matches'][0]['table']);
        $this->assertSame($id, $payload['matches'][0]['id']);
        $this->assertSame(1, $payload['matches'][0]['company_id']);
        $this->assertArrayHasKey('code', $payload['matches'][0]['fields']);
        $this->assertDatabaseHas('customers', [
            'id' => $id,
            'code' => 'SIM-TEST-CUST-001',
        ]);
    }

    public function test_company_filter_excludes_other_tenant_rows(): void
    {
        Company::unguarded(fn () => Company::firstOrCreate(['id' => 2], ['name' => 'Other company']));
        DB::table('customers')->insert([
            'company_id' => 2,
            'code' => 'SIM-TEST-OTHER-001',
            'name' => 'Dữ liệu thử tenant khác',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withoutMockingConsoleOutput();
        $this->assertSame(0, $this->artisan('data:audit-isolation', ['--json' => true, '--company' => '1']));

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('clean', $payload['status']);
        $this->assertSame([], $payload['matches']);
    }

    public function test_related_party_names_are_scanned_on_transaction_sources(): void
    {
        $id = DB::table('purchase_orders')->insertGetId([
            'company_id' => 1,
            'order_number' => 'PO-REAL-CODE-001',
            'order_date' => '2026-09-04',
            'supplier_name' => 'SIM-TEST Nhà cung cấp chưa phân loại',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withoutMockingConsoleOutput();
        $this->assertSame(1, $this->artisan('data:audit-isolation', ['--json' => true]));

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $match = collect($payload['matches'])->firstWhere('table', 'purchase_orders');
        $this->assertNotNull($match);
        $this->assertSame($id, $match['id']);
        $this->assertArrayHasKey('supplier_name', $match['fields']);
    }
}
