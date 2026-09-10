<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ChartOfAccount;
use App\Models\Item;
use App\Models\User;
use App\Services\InventoryIssueService;
use App\Services\InventoryReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryProductionAccountEvidenceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Inventory production boundary',
            'tax_code' => 'INV-PROD-BOUNDARY',
        ]);
        $actor = User::factory()->create();
        $this->configureAccountingTenant($actor, $this->company);
        Sanctum::actingAs($actor);

        $this->item = Item::create([
            'company_id' => $this->company->id,
            'code' => 'INV-PROD-ITEM',
            'name' => 'Production boundary item',
            'type' => 'goods',
            'inventory_account' => '1561',
        ]);

        foreach ([
            ['1561', 'Hàng hóa', 'asset', 'debit'],
            ['331', 'Phải trả người bán', 'liability', 'credit'],
            ['632', 'Giá vốn hàng bán', 'expense', 'debit'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create([
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'nature' => $nature,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }
    }

    public function test_production_receipt_create_and_update_reject_missing_account_evidence_before_mutation(): void
    {
        $service = app(InventoryReceiptService::class);
        $receipt = $service->create($this->receiptPayload('INV-PROD-REC-BASE', [
            'debit_account' => '1561',
            'credit_account' => '331',
        ]));

        Config::set('app.env', 'production');
        try {
            $productionReceipt = $service->create($this->receiptPayload('INV-PROD-REC-EXPLICIT', [
                'debit_account' => '1561',
                'credit_account' => '331',
            ]));
            $this->assertMissingAccountEvidence(fn () => $service->create($this->receiptPayload('INV-PROD-REC-MISSING')));
            $this->assertMissingAccountEvidence(fn () => $service->update($receipt->id, [
                'company_id' => $this->company->id,
                'lines' => [$this->line()],
            ]));
        } finally {
            Config::set('app.env', 'testing');
        }

        $this->assertDatabaseMissing('inventory_receipts', ['voucher_number' => 'INV-PROD-REC-MISSING']);
        $this->assertDatabaseHas('inventory_receipt_lines', [
            'inventory_receipt_id' => $productionReceipt->id,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);
        $this->assertDatabaseHas('inventory_receipt_lines', [
            'inventory_receipt_id' => $receipt->id,
            'debit_account' => '1561',
            'credit_account' => '331',
        ]);
    }

    public function test_production_issue_create_and_update_reject_missing_account_evidence_before_mutation(): void
    {
        $service = app(InventoryIssueService::class);
        $issue = $service->create($this->issuePayload('INV-PROD-ISS-BASE', [
            'debit_account' => '632',
            'credit_account' => '1561',
        ]));

        Config::set('app.env', 'production');
        try {
            $productionIssue = $service->create($this->issuePayload('INV-PROD-ISS-EXPLICIT', [
                'debit_account' => '632',
                'credit_account' => '1561',
            ]));
            $this->assertMissingAccountEvidence(fn () => $service->create($this->issuePayload('INV-PROD-ISS-MISSING')));
            $this->assertMissingAccountEvidence(fn () => $service->update($issue->id, [
                'company_id' => $this->company->id,
                'lines' => [$this->line()],
            ]));
        } finally {
            Config::set('app.env', 'testing');
        }

        $this->assertDatabaseMissing('inventory_issues', ['voucher_number' => 'INV-PROD-ISS-MISSING']);
        $this->assertDatabaseHas('inventory_issue_lines', [
            'inventory_issue_id' => $productionIssue->id,
            'debit_account' => '632',
            'credit_account' => '1561',
        ]);
        $this->assertDatabaseHas('inventory_issue_lines', [
            'inventory_issue_id' => $issue->id,
            'debit_account' => '632',
            'credit_account' => '1561',
        ]);
    }

    public function test_create_and_update_cannot_mark_unposted_inventory_documents_as_posted(): void
    {
        $receiptService = app(InventoryReceiptService::class);
        $receipt = $receiptService->create($this->receiptPayload('INV-STATUS-REC', [
            'debit_account' => '1561',
            'credit_account' => '331',
        ]) + ['status' => 'posted']);

        $this->assertFalse((bool) $receipt->is_posted);
        $this->assertSame('draft', $receipt->status);

        $updatedReceipt = $receiptService->update($receipt->id, [
            'company_id' => $this->company->id,
            'status' => 'posted',
        ]);
        $this->assertFalse((bool) $updatedReceipt->is_posted);
        $this->assertSame('draft', $updatedReceipt->status);

        $issueService = app(InventoryIssueService::class);
        $issue = $issueService->create($this->issuePayload('INV-STATUS-ISS', [
            'debit_account' => '632',
            'credit_account' => '1561',
        ]) + ['status' => 'posted']);

        $this->assertFalse((bool) $issue->is_posted);
        $this->assertSame('draft', $issue->status);

        $updatedIssue = $issueService->update($issue->id, [
            'company_id' => $this->company->id,
            'status' => 'posted',
        ]);
        $this->assertFalse((bool) $updatedIssue->is_posted);
        $this->assertSame('draft', $updatedIssue->status);
    }

    private function assertMissingAccountEvidence(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Production inventory operations must not use inferred account defaults.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.debit_account', $exception->errors());
            $this->assertArrayHasKey('lines.0.credit_account', $exception->errors());
        }
    }

    /** @param array<string, mixed> $line */
    private function receiptPayload(string $number, array $line = []): array
    {
        return [
            'company_id' => $this->company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-24',
            'posting_date' => '2026-08-24',
            'lines' => [[...$this->line(), ...$line]],
        ];
    }

    /** @param array<string, mixed> $line */
    private function issuePayload(string $number, array $line = []): array
    {
        return [
            'company_id' => $this->company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-24',
            'posting_date' => '2026-08-24',
            'lines' => [[...$this->line(), ...$line]],
        ];
    }

    /** @return array<string, int> */
    private function line(): array
    {
        return [
            'item_id' => $this->item->id,
            'quantity' => 1,
            'unit_price' => 100,
        ];
    }
}
