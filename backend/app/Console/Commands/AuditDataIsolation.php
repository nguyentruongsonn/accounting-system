<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only release gate for data accidentally left by browser/UAT sessions.
 *
 * This command deliberately does not delete, anonymise, or otherwise mutate
 * application data. A non-zero exit code means an owner must classify the
 * rows before release; it is not permission to clean them automatically.
 */
final class AuditDataIsolation extends Command
{
    protected $signature = 'data:audit-isolation
        {--company= : Limit the audit to one company ID}
        {--json : Emit a machine-readable summary}';

    protected $description = 'Scan operational data for likely simulation/test markers without changing records.';

    /** @var array<string, list<string>> */
    private const SOURCES = [
        'customers' => ['code', 'name', 'tax_code', 'address', 'phone', 'email'],
        'suppliers' => ['code', 'name', 'tax_code', 'address', 'phone', 'email'],
        'products' => ['code', 'name', 'description'],
        'items' => ['code', 'name', 'description'],
        'warehouses' => ['code', 'name', 'description'],
        'cash_receipts' => ['voucher_number', 'contact_name', 'payer_name', 'payer_address', 'reason'],
        'cash_payments' => ['voucher_number', 'contact_name', 'receiver_name', 'receiver_address', 'reason'],
        'bank_receipts' => ['voucher_number', 'contact_name', 'payer_name', 'payer_address', 'reason'],
        'bank_payments' => ['voucher_number', 'contact_name', 'receiver_name', 'receiver_address', 'reason'],
        'purchase_orders' => ['code', 'order_number', 'voucher_number', 'supplier_code', 'supplier_name', 'description', 'notes'],
        'purchase_invoices' => ['invoice_number', 'voucher_number', 'supplier_name', 'description', 'notes'],
        'purchase_returns' => ['voucher_number', 'supplier_name', 'description', 'notes'],
        'sales_quotes' => ['code', 'quote_number', 'customer_name', 'description', 'notes'],
        'sales_orders' => ['code', 'order_number', 'voucher_number', 'customer_name', 'description', 'notes'],
        'sales_invoices' => ['invoice_number', 'voucher_number', 'customer_name', 'receiver_name', 'description', 'notes'],
        'sales_returns' => ['voucher_number', 'customer_name', 'description', 'notes'],
        'inventory_receipts' => ['voucher_number', 'contact_name', 'deliverer_name', 'description', 'notes'],
        'inventory_issues' => ['voucher_number', 'contact_name', 'receiver_name', 'description', 'notes'],
        'inventory_transfers' => ['voucher_number', 'description', 'notes'],
        'inventory_adjustments' => ['voucher_number', 'description', 'notes'],
        'inventory_counts' => ['voucher_number', 'description', 'notes'],
    ];

    /** @var list<string> */
    private const MARKERS = [
        '/\bSIM(?:ULATED)?\b/i',
        '/\b(?:TEST|DEMO|MOCK|SAMPLE|FAKE)(?:[-_ ]?(?:DATA|RECORD|CUSTOMER|SUPPLIER|ITEM|BROWSER))?\b/i',
    ];

    public function handle(): int
    {
        $companyId = $this->normaliseCompanyId($this->option('company'));
        if ($companyId === false) {
            return self::FAILURE;
        }

        $summary = $this->audit($companyId);
        $summary['status'] = $summary['matches'] === [] ? 'clean' : 'review_required';

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderHumanSummary($summary, $companyId);
        }

        return $summary['matches'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{scanned_sources: list<string>, scanned_rows: int, matches: list<array<string, mixed>>}
     */
    private function audit(?int $companyId): array
    {
        $sources = [];
        $matches = [];
        $scannedRows = 0;

        foreach (self::SOURCES as $table => $configuredColumns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            if (! in_array('id', $columns, true) || ! in_array('company_id', $columns, true)) {
                continue;
            }

            $textColumns = array_values(array_intersect($configuredColumns, $columns));
            if ($textColumns === []) {
                continue;
            }

            $sources[] = $table;
            $query = DB::table($table)->select(array_merge(['id', 'company_id'], $textColumns));
            if ($companyId !== null) {
                $query->where('company_id', $companyId);
            }

            $query->orderBy('id')->chunkById(500, function ($rows) use (&$matches, &$scannedRows, $table, $textColumns): void {
                $scannedRows += count($rows);
                foreach ($rows as $row) {
                    $matchedFields = [];
                    foreach ($textColumns as $column) {
                        $value = $row->{$column} ?? null;
                        if (! is_scalar($value) || trim((string) $value) === '') {
                            continue;
                        }
                        $value = (string) $value;
                        if ($this->containsMarker($value)) {
                            $matchedFields[$column] = mb_substr($value, 0, 160);
                        }
                    }

                    if ($matchedFields !== []) {
                        $matches[] = [
                            'table' => $table,
                            'id' => (int) $row->id,
                            'company_id' => (int) $row->company_id,
                            'fields' => $matchedFields,
                        ];
                    }
                }
            }, 'id');
        }

        return [
            'scanned_sources' => $sources,
            'scanned_rows' => $scannedRows,
            'matches' => $matches,
        ];
    }

    private function containsMarker(string $value): bool
    {
        foreach (self::MARKERS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normaliseCompanyId(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! ctype_digit((string) $value) || (int) $value < 1) {
            $this->error('Company ID must be a positive integer.');

            return false;
        }

        return (int) $value;
    }

    /** @param array{status: string, scanned_sources: list<string>, scanned_rows: int, matches: list<array<string, mixed>>} $summary */
    private function renderHumanSummary(array $summary, ?int $companyId): void
    {
        $scope = $companyId === null ? 'all companies' : "company {$companyId}";
        $this->line("Data isolation audit ({$scope})");
        $this->line(sprintf('Scanned %d row(s) across %d source table(s).', $summary['scanned_rows'], count($summary['scanned_sources'])));

        if ($summary['matches'] === []) {
            $this->info('No likely simulation/test records found.');

            return;
        }

        $this->warn(sprintf('%d row(s) require owner review; no data was changed.', count($summary['matches'])));
        $this->table(
            ['Table', 'ID', 'Company', 'Matched fields'],
            array_map(static fn (array $match): array => [
                $match['table'],
                $match['id'],
                $match['company_id'],
                implode(', ', array_keys($match['fields'])),
            ], $summary['matches'])
        );
    }
}
