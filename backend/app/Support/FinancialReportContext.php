<?php

namespace App\Support;

use App\Models\FiscalYear;

/**
 * The resolved, tenant-bound reporting period used by a financial-report read.
 *
 * This is deliberately a transport object: it does not prescribe any report
 * formula or statutory form.
 */
final class FinancialReportContext
{
    /** @param array<string, mixed> $regimeMetadata */
    public function __construct(
        public readonly int $companyId,
        public readonly FiscalYear $fiscalYear,
        public readonly string $fromDate,
        public readonly string $toDate,
        public readonly array $regimeMetadata,
    ) {}
}
