<?php

namespace Tests\Unit;

use Tests\TestCase;

final class AccountingConfigTest extends TestCase
{
    public function test_internal_two_role_defaults_remain_explicit(): void
    {
        self::assertTrue((bool) config('accounting.direct_posting_mode'));
        self::assertFalse((bool) config('accounting.enforce_period_close_signoff'));
        self::assertFalse((bool) config('accounting.enforce_period_close_account_mappings'));
    }
}
