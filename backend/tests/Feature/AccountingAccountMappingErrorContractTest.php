<?php

namespace Tests\Feature;

use App\Exceptions\AccountingAccountMappingUnavailableException;
use App\Support\ApiErrorResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AccountingAccountMappingErrorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapping_conflict_keeps_stable_code_and_exposes_safe_field_detail(): void
    {
        $detail = 'Account mapping [inventory.voucher/inventory_debit] must resolve to exactly one approved mapping; found 0.';
        $response = app(ApiErrorResponder::class)->toResponse(
            new AccountingAccountMappingUnavailableException($detail),
            Request::create('/api/v1/inventory/receipts/42/post', 'POST'),
        );

        $payload = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Approved posting account mapping evidence is not available.', $payload['error']);
        $this->assertSame('ACCOUNT_MAPPING_UNAVAILABLE', $payload['error_code']);
        $this->assertSame([$detail], $payload['errors']['account_mapping']);
        $this->assertArrayHasKey('request_id', $payload);
    }
}
