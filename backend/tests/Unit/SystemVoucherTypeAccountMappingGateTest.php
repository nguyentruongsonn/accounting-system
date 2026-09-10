<?php

namespace Tests\Unit;

use App\Enums\SystemVoucherType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SystemVoucherTypeAccountMappingGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_voucher_defaults_fail_closed_before_hard_coded_lines_when_enforced(): void
    {
        config()->set('accounting.enforce_voucher_reference_account_mappings', true);

        try {
            SystemVoucherType::resolveCrossVoucherDefaults(
                SystemVoucherType::CASH_RECEIPT,
                SystemVoucherType::SALES_INVOICE,
                (object) ['total_amount' => 100],
            );
            $this->fail('Expected cross-voucher account defaults to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_mappings', $exception->errors());
        }
    }
}
