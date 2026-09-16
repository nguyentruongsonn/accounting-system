<?php

namespace App\Services\Concerns;

use App\Services\PostedDependentDocumentGuard;

/** Reusable lifecycle guard for downstream posted voucher references. */
trait GuardsPostedDependentDocuments
{
    protected function assertNoPostedDependentDocuments(int $companyId, string $targetType, int $targetId): void
    {
        app(PostedDependentDocumentGuard::class)->assertNone($companyId, $targetType, $targetId);
    }
}
