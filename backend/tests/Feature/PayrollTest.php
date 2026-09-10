<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->company = Company::create([
            'name' => 'Test Company',
            'tax_code' => '123456789',
            'address' => 'Test Address',
        ]);
        $this->configureAccountingTenant($this->user, $this->company);

        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '6421', 'name' => 'Salary Expense', 'type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);
        ChartOfAccount::create(['company_id' => $this->company->id, 'code' => '3341', 'name' => 'Payable to Employees', 'type' => 'liability', 'nature' => 'debit', 'level' => 1, 'is_parent' => 0]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'code' => 'E001',
            'name' => 'Test Employee',
            'department' => 'IT',
        ]);
    }

    public function test_can_create_payroll()
    {
        $foreignCompany = Company::create([
            'name' => 'Foreign Company',
            'tax_code' => '987654321',
            'address' => 'Other Address',
        ]);

        $payload = [
            // A malicious client company ID must not move a payroll into a
            // different tenant; the controller uses the authenticated company.
            'company_id' => $foreignCompany->id,
            'voucher_number' => 'PR-001',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'month' => '2026-08',
            'total_amount' => 10000000,
            'description' => 'August Payroll',
            'lines' => [
                [
                    'employee_id' => $this->employee->id,
                    'employee_name' => $this->employee->name,
                    'department' => 'IT',
                    'basic_salary' => 10000000,
                    'net_salary' => 10000000,
                    'debit_account' => '6421',
                    'credit_account' => '3341',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/payroll', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('voucher_number', 'PR-001');

        $this->assertDatabaseHas('payrolls', [
            'voucher_number' => 'PR-001',
            'total_amount' => 10000000,
            'company_id' => $this->company->id,
        ]);

        $this->assertDatabaseHas('payroll_lines', [
            'employee_name' => 'Test Employee',
            'net_salary' => 10000000,
        ]);
    }

    public function test_can_post_payroll_to_gl()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PR-002',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'month' => '2026-08',
            'total_amount' => 10000000,
            'description' => 'August Payroll',
            'lines' => [
                [
                    'employee_id' => $this->employee->id,
                    'employee_name' => $this->employee->name,
                    'department' => 'IT',
                    'basic_salary' => 10000000,
                    'net_salary' => 10000000,
                    'debit_account' => '6421',
                    'credit_account' => '3341',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/payroll', $payload);
        $payrollId = $response->json('id');

        $postResponse = $this->postJson("/api/v1/payroll/{$payrollId}/post");
        $postResponse->assertStatus(200);

        $this->assertDatabaseHas('payrolls', [
            'id' => $payrollId,
            'is_posted' => true,
        ]);

        $payroll = Payroll::find($payrollId);
        $this->assertNotNull($payroll->journal_entry_id);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $payroll->journal_entry_id,
            'status' => 'posted',
        ]);
        $this->assertSame('2026-08-14', JournalEntry::findOrFail($payroll->journal_entry_id)->posting_date->toDateString());
    }

    public function test_payroll_rejects_employee_from_another_company(): void
    {
        $foreignCompany = Company::create(['name' => 'Foreign Payroll', 'tax_code' => 'PAY-FOREIGN']);
        $foreignEmployee = Employee::create(['company_id' => $foreignCompany->id, 'code' => 'F001', 'name' => 'Foreign employee']);

        $this->postJson('/api/v1/payroll', [
            'voucher_number' => 'PR-FOREIGN-EMP', 'voucher_date' => '2026-08-14', 'month' => '2026-08',
            'lines' => [[
                'employee_id' => $foreignEmployee->id, 'employee_name' => $foreignEmployee->name,
                'net_salary' => 100, 'debit_account' => '6421', 'credit_account' => '3341',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.employee_id');
    }

    public function test_can_void_payroll()
    {
        $payload = [
            'company_id' => $this->company->id,
            'voucher_number' => 'PR-003',
            'voucher_date' => '2026-08-14',
            'posting_date' => '2026-08-14',
            'month' => '2026-08',
            'total_amount' => 10000000,
            'description' => 'August Payroll',
            'lines' => [
                [
                    'employee_id' => $this->employee->id,
                    'employee_name' => $this->employee->name,
                    'department' => 'IT',
                    'basic_salary' => 10000000,
                    'net_salary' => 10000000,
                    'debit_account' => '6421',
                    'credit_account' => '3341',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/payroll', $payload);
        $payrollId = $response->json('id');

        $this->postJson("/api/v1/payroll/{$payrollId}/post");

        $voidResponse = $this->postJson("/api/v1/payroll/{$payrollId}/void");
        $voidResponse->assertStatus(200);

        $this->assertDatabaseHas('payrolls', [
            'id' => $payrollId,
            'is_posted' => false,
        ]);

        $payroll = Payroll::find($payrollId);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $payroll->journal_entry_id,
            'status' => 'voided',
        ]);
    }
}
