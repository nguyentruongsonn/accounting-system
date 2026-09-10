<?php

namespace Tests\Unit;

use App\Models\BankPayment;
use App\Models\BankPaymentLine;
use App\Models\BankReceipt;
use App\Models\BankReceiptLine;
use App\Models\ClosingRule;
use App\Models\Company;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesQuote;
use App\Models\SalesQuoteLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MisaMilestone1EmpiricalChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Empirical Test Company',
            'tax_code' => '0100000001',
            'address' => 'Hanoi, Vietnam',
        ]);
    }

    /**
     * Challenge 1: ClosingRule Sequence and Exact Ordering of 11 Standard Seeded Rules
     */
    public function test_closing_rules_exact_sequence_and_ordering(): void
    {
        $rules = ClosingRule::orderBy('sequence')->get();

        $this->assertCount(11, $rules, 'There must be exactly 11 seeded closing rules');

        $expectedRules = [
            10 => ['code' => 'KC_REV_511_911', 'debit' => '511', 'credit' => '911', 'type' => 'revenue'],
            20 => ['code' => 'KC_REV_515_911', 'debit' => '515', 'credit' => '911', 'type' => 'revenue'],
            30 => ['code' => 'KC_REV_711_911', 'debit' => '711', 'credit' => '911', 'type' => 'revenue'],
            40 => ['code' => 'KC_EXP_632_911', 'debit' => '911', 'credit' => '632', 'type' => 'expense'],
            50 => ['code' => 'KC_EXP_635_911', 'debit' => '911', 'credit' => '635', 'type' => 'expense'],
            60 => ['code' => 'KC_EXP_641_911', 'debit' => '911', 'credit' => '641', 'type' => 'expense'],
            70 => ['code' => 'KC_EXP_642_911', 'debit' => '911', 'credit' => '642', 'type' => 'expense'],
            80 => ['code' => 'KC_EXP_811_911', 'debit' => '911', 'credit' => '811', 'type' => 'expense'],
            90 => ['code' => 'KC_EXP_821_911', 'debit' => '911', 'credit' => '821', 'type' => 'expense'],
            100 => ['code' => 'KC_RES_PROFIT_911_4212', 'debit' => '911', 'credit' => '4212', 'type' => 'result'],
            110 => ['code' => 'KC_RES_LOSS_4212_911', 'debit' => '4212', 'credit' => '911', 'type' => 'result'],
        ];

        $prevSeq = 0;
        foreach ($rules as $rule) {
            $this->assertIsInt($rule->sequence);
            $this->assertIsBool($rule->is_active);
            $this->assertTrue($rule->sequence > $prevSeq, "Sequence {$rule->sequence} must be strictly greater than {$prevSeq}");
            $prevSeq = $rule->sequence;

            $this->assertArrayHasKey($rule->sequence, $expectedRules, "Unexpected sequence {$rule->sequence}");
            $expected = $expectedRules[$rule->sequence];

            $this->assertEquals($expected['code'], $rule->rule_code);
            $this->assertEquals($expected['debit'], $rule->debit_account);
            $this->assertEquals($expected['credit'], $rule->credit_account);
            $this->assertEquals($expected['type'], $rule->rule_type);
        }
    }

    /**
     * Challenge 2: SalesQuote and SalesOrder Mass Assignment & Casts
     */
    public function test_sales_quote_and_order_casts_and_mass_assignment(): void
    {
        $quoteData = [
            'company_id' => $this->company->id,
            'quote_number' => 'BG-TEST-001',
            'quote_date' => '2026-08-20',
            'expiry_date' => '2026-09-20',
            'customer_code' => 'CUST01',
            'customer_name' => 'Acme Corp',
            'sub_total' => '1000000.50',
            'discount_amount' => '50000.00',
            'vat_amount' => '95000.05',
            'total_amount' => '1045000.55',
            'grand_total' => '1045000.55',
            'status' => 'draft',
            'currency' => 'VND',
            'exchange_rate' => '1.0000',
            'referenced_vouchers' => [
                ['voucher_type' => 'YCBG', 'voucher_number' => 'REQ01', 'total_amount' => 1000000],
            ],
        ];

        $quote = SalesQuote::create($quoteData);

        $this->assertInstanceOf(SalesQuote::class, $quote);
        $this->assertEquals('2026-08-20', $quote->quote_date->format('Y-m-d'));
        $this->assertEquals('2026-09-20', $quote->expiry_date->format('Y-m-d'));
        $this->assertIsArray($quote->referenced_vouchers);
        $this->assertEquals('REQ01', $quote->referenced_vouchers[0]['voucher_number']);

        $orderData = [
            'company_id' => $this->company->id,
            'quote_id' => $quote->id,
            'sales_quote_id' => $quote->id,
            'order_number' => 'SO-TEST-001',
            'order_date' => '2026-08-21',
            'delivery_date' => '2026-08-25',
            'due_days' => 45,
            'sub_total' => '1000000.50',
            'discount_amount' => '50000.00',
            'vat_amount' => '95000.05',
            'total_amount' => '1045000.55',
            'grand_total' => '1045000.55',
            'status' => 'confirmed',
            'delivery_status' => 'not_delivered',
            'invoice_status' => 'not_invoiced',
            'exchange_rate' => '1.0000',
            'referenced_vouchers' => [
                ['voucher_type' => 'Báo giá', 'voucher_number' => 'BG-TEST-001', 'total_amount' => 1045000.55],
            ],
        ];

        $order = SalesOrder::create($orderData);

        $this->assertInstanceOf(SalesOrder::class, $order);
        $this->assertEquals('2026-08-21', $order->order_date->format('Y-m-d'));
        $this->assertEquals('2026-08-25', $order->delivery_date->format('Y-m-d'));
        $this->assertSame(45, $order->due_days);
        $this->assertIsArray($order->referenced_vouchers);
        $this->assertEquals($quote->id, $order->quote->id);
    }

    /**
     * Challenge 3: HasVoucherReferences Trait syncReferences() under varied payload shapes
     */
    public function test_sync_references_with_various_payload_shapes(): void
    {
        $quote = SalesQuote::create([
            'company_id' => $this->company->id,
            'quote_number' => 'BG-SYNC-001',
            'quote_date' => '2026-08-20',
            'total_amount' => 1000000,
        ]);

        // Shape 1: Null payload
        $quote->syncReferences(null);
        $this->assertCount(0, $quote->references);

        // Shape 2: Empty array payload
        $quote->syncReferences([]);
        $this->assertCount(0, $quote->references);

        // Shape 3: Single reference with target_type / target_id format
        $quote->syncReferences([
            [
                'target_type' => 'App\\Models\\SalesQuote',
                'target_id' => 999,
                'voucher_type' => 'Yêu cầu báo giá',
                'voucher_number' => 'YCBG-999',
                'voucher_date' => '2026-08-19 14:30:00', // timestamp string
                'total_amount' => 500000,
                'description' => 'Test sync note',
            ],
        ]);
        $this->assertCount(1, $quote->fresh()->references);
        $ref = $quote->fresh()->references->first();
        $this->assertEquals('App\\Models\\SalesQuote', $ref->target_type);
        $this->assertEquals(999, $ref->target_id);
        $this->assertEquals('YCBG-999', $ref->target_voucher_number);
        $this->assertEquals('2026-08-19', $ref->target_voucher_date->format('Y-m-d'));
        $this->assertEquals(500000, $ref->target_total_amount);

        // Shape 4: Fallback keys format (model, real_id, id)
        $quote->syncReferences([
            [
                'model' => 'App\\Models\\BankReceipt',
                'real_id' => 888,
                'voucher_number' => 'BC-888',
                'voucher_date' => '2026-08-18',
                'total_amount' => 300000,
            ],
        ]);
        $this->assertCount(1, $quote->fresh()->references);
        $ref = $quote->fresh()->references->first();
        $this->assertEquals('App\\Models\\BankReceipt', $ref->target_type);
        $this->assertEquals(888, $ref->target_id);
        $this->assertEquals('BC-888', $ref->target_voucher_number);
        $this->assertEquals('Chứng từ gốc', $ref->target_voucher_type); // Default fallback

        // Shape 5: Multiple references from different targets
        $quote->syncReferences([
            [
                'target_type' => 'App\\Models\\BankReceipt',
                'target_id' => 101,
                'voucher_type' => 'Thu tiền gửi',
                'voucher_number' => 'BC-101',
                'total_amount' => 100000,
            ],
            [
                'target_type' => 'App\\Models\\PurchaseInvoice',
                'target_id' => 102,
                'voucher_type' => 'Hóa đơn mua',
                'voucher_number' => 'HDM-102',
                'total_amount' => 200000,
            ],
            [
                'target_type' => 'App\\Models\\SalesOrder',
                'target_id' => 103,
                'voucher_type' => 'Đơn đặt hàng',
                'voucher_number' => 'DDH-103',
                'total_amount' => 300000,
            ],
        ]);
        $this->assertCount(3, $quote->fresh()->references);

        // Shape 6: Re-syncing with empty clears all previous references
        $quote->syncReferences([]);
        $this->assertCount(0, $quote->fresh()->references);
    }

    /**
     * Challenge 4: Model Fillable vs Table Schema Inspection across All M1 Voucher Models
     */
    public function test_all_model_fillables_match_database_schema(): void
    {
        $models = [
            'SalesQuote' => new SalesQuote,
            'SalesQuoteLine' => new SalesQuoteLine,
            'SalesOrder' => new SalesOrder,
            'SalesOrderLine' => new SalesOrderLine,
            'ClosingRule' => new ClosingRule,
            'BankReceipt' => new BankReceipt,
            'BankReceiptLine' => new BankReceiptLine,
            'BankPayment' => new BankPayment,
            'BankPaymentLine' => new BankPaymentLine,
            'PurchaseInvoice' => new PurchaseInvoice,
            'PurchaseInvoiceLine' => new PurchaseInvoiceLine,
            'SalesInvoice' => new SalesInvoice,
            'SalesInvoiceLine' => new SalesInvoiceLine,
            'InventoryReceipt' => new InventoryReceipt,
            'InventoryReceiptLine' => new InventoryReceiptLine,
            'InventoryIssue' => new InventoryIssue,
            'InventoryIssueLine' => new InventoryIssueLine,
            'JournalEntry' => new JournalEntry,
            'JournalEntryLine' => new JournalEntryLine,
        ];

        $missingColumns = [];

        foreach ($models as $name => $model) {
            $table = $model->getTable();
            $fillables = $model->getFillable();

            foreach ($fillables as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingColumns[] = "Model [{$name}] table [{$table}] is missing fillable column [{$column}]";
                }
            }
        }

        $this->assertEmpty($missingColumns, "Schema discrepancies detected:\n".implode("\n", $missingColumns));
    }
}
