<?php

namespace Tests\Feature;

use App\Exceptions\ReportDefinitionUnavailableException;
use App\Models\User;
use App\Services\ManagementReportDefinitionContractHasher;
use App\Services\ManagementReportDefinitionLifecycleService;
use App\Services\ManagementReportDefinitionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementReportDefinitionAmountContractTest extends TestCase
{
    use RefreshDatabase;

    private const STOCK_AMOUNT_CONTRACT = [
        'quantity' => [
            'source_representation' => 'integer',
            'scale' => 0,
            'output_representation' => 'integer_string',
        ],
        'money' => [
            'source_representation' => 'integer',
            'scale' => 0,
            'output_representation' => 'integer_string',
        ],
    ];

    public function test_amount_contract_is_optional_for_existing_aging_definitions(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $definitions = app(ManagementReportDefinitionLifecycleService::class);
        $published = $definitions->publish($actor, $definitions->createDraft(
            $actor,
            'accounts_payable_aging.v2',
            '2026.08.1',
            ['sources' => ['purchase_invoices'], 'cutoff' => 'as_of_date'],
            ['outstanding' => 'allocation-aware'],
        ));

        $this->assertNull($published->amount_contract);
        $this->assertTrue($published->is(app(ManagementReportDefinitionRegistry::class)
            ->requireExecutable($actor->company_id, 'accounts_payable_aging.v2')));
    }

    public function test_amount_contract_is_canonically_hashed_and_immutable_after_publication(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $definitions = app(ManagementReportDefinitionLifecycleService::class);
        $published = $definitions->publish($actor, $definitions->createDraft(
            $actor,
            'stock_movement.v2',
            '2026.08.1',
            ['sources' => ['inventory_receipt', 'inventory_issue']],
            ['roll_forward' => 'signed'],
            self::STOCK_AMOUNT_CONTRACT,
        ));

        $reorderedAmountContract = [
            'money' => self::STOCK_AMOUNT_CONTRACT['money'],
            'quantity' => self::STOCK_AMOUNT_CONTRACT['quantity'],
        ];
        $this->assertSame($published->contract_hash, app(ManagementReportDefinitionContractHasher::class)->hash(
            'stock_movement.v2',
            '2026.08.1',
            ['sources' => ['inventory_receipt', 'inventory_issue']],
            ['roll_forward' => 'signed'],
            $reorderedAmountContract,
        ));

        $published->amount_contract = ['money' => ['scale' => 2]];
        $this->expectException(\LogicException::class);
        $published->save();
    }

    public function test_exact_amount_contract_resolver_fails_closed_for_missing_or_scale_mismatched_stock_contract(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $definitions = app(ManagementReportDefinitionLifecycleService::class);
        $registry = app(ManagementReportDefinitionRegistry::class);

        $aging = $definitions->publish($actor, $definitions->createDraft(
            $actor,
            'stock_movement.v2',
            'without-amount-contract',
            ['sources' => ['inventory_receipt', 'inventory_issue']],
            ['roll_forward' => 'signed'],
        ));
        $this->assertNull($aging->amount_contract);

        try {
            $registry->requireExecutableWithExactAmountContract(
                $actor->company_id,
                'stock_movement.v2',
                self::STOCK_AMOUNT_CONTRACT,
            );
            $this->fail('A stock definition without D-04 amount facts must not resolve.');
        } catch (ReportDefinitionUnavailableException) {
            $this->addToAssertionCount(1);
        }

        $definitions->publish($actor, $definitions->createDraft(
            $actor,
            'stock_movement.v2',
            'scale-mismatch',
            ['sources' => ['inventory_receipt', 'inventory_issue']],
            ['roll_forward' => 'signed'],
            array_replace_recursive(self::STOCK_AMOUNT_CONTRACT, ['money' => ['scale' => 2]]),
        ));

        $this->expectException(ReportDefinitionUnavailableException::class);
        $registry->requireExecutableWithExactAmountContract(
            $actor->company_id,
            'stock_movement.v2',
            self::STOCK_AMOUNT_CONTRACT,
        );
    }

    public function test_exact_amount_contract_resolver_treats_object_key_order_as_non_semantic(): void
    {
        $actor = User::factory()->create(['company_id' => 1]);
        $definitions = app(ManagementReportDefinitionLifecycleService::class);
        $published = $definitions->publish($actor, $definitions->createDraft(
            $actor,
            'stock_movement.v2',
            'ordered-contract',
            ['sources' => ['inventory_receipt', 'inventory_issue']],
            ['roll_forward' => 'signed'],
            self::STOCK_AMOUNT_CONTRACT,
        ));

        $reordered = ['money' => self::STOCK_AMOUNT_CONTRACT['money'], 'quantity' => self::STOCK_AMOUNT_CONTRACT['quantity']];
        $resolved = app(ManagementReportDefinitionRegistry::class)->requireExecutableWithExactAmountContract(
            $actor->company_id,
            'stock_movement.v2',
            $reordered,
        );

        $this->assertTrue($published->is($resolved));
    }

    public function test_amount_contract_changes_the_hash_but_null_preserves_the_legacy_hash_shape(): void
    {
        $hasher = app(ManagementReportDefinitionContractHasher::class);
        $withoutAmount = $hasher->hash('accounts_receivable_aging.v2', '1', ['a' => 1], ['b' => 2]);
        $withAmount = $hasher->hash('accounts_receivable_aging.v2', '1', ['a' => 1], ['b' => 2], ['money' => ['scale' => 0]]);

        $this->assertNotSame($withoutAmount, $withAmount);
        $this->assertSame($withoutAmount, $hasher->hash('accounts_receivable_aging.v2', '1', ['a' => 1], ['b' => 2], null));
    }
}
