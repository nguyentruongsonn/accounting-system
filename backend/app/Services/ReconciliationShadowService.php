<?php

namespace App\Services;

use App\Models\AllocationLog;
use App\Models\AssetDisposal;
use App\Models\AssetRevaluation;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\ChartOfAccount;
use App\Models\DepreciationLog;
use App\Models\FixedAsset;
use App\Models\InventoryIssue;
use App\Models\InventoryReceipt;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\Period;
use App\Models\PurchaseDiscount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\ReconciliationCheckResult;
use App\Models\ReconciliationRun;
use App\Models\SalesDiscount;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReconciliationShadowService
{
    public const ALGORITHM_VERSION = 'gl-shadow-integrity.v1';

    private const EVIDENCE_LIMIT = 200;

    public function __construct(private readonly AuditService $auditService) {}

    /**
     * Execute an immutable, non-enforcing GL integrity snapshot.
     *
     * @return array{run: ReconciliationRun, replayed: bool}
     */
    public function execute(int $companyId, int $periodId, string $idempotencyKey, ?int $actorId): array
    {
        $companyId = $this->requireCompanyId($companyId);
        $request = [
            'basis' => 'shadow',
            'company_id' => $companyId,
            'period_id' => $periodId,
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
        $requestHash = $this->hash($request);

        $existing = $this->findByIdempotencyKey($companyId, $idempotencyKey);
        if ($existing !== null) {
            return $this->replayOrConflict($existing, $requestHash);
        }

        try {
            return DB::transaction(function () use ($companyId, $periodId, $idempotencyKey, $actorId, $requestHash): array {
                $existing = ReconciliationRun::withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return $this->replayOrConflict($existing, $requestHash);
                }

                $period = Period::query()
                    ->with('fiscalYear')
                    ->whereKey($periodId)
                    ->whereHas('fiscalYear', fn ($query) => $query->where('company_id', $companyId))
                    ->lockForUpdate()
                    ->first();
                if ($period === null || $period->fiscalYear === null) {
                    throw new NotFoundHttpException('Accounting period was not found for the authenticated company.');
                }

                $startedAt = now();
                $cutoffAt = Carbon::instance($startedAt->toDateTime());
                $snapshot = $this->buildSnapshot($companyId, $period, $cutoffAt);
                $completedAt = now();

                $resultPayloads = collect($snapshot['results'])
                    ->sortBy(fn (array $result): string => $result['check_code'].'|'.$result['fingerprint'])
                    ->values();
                $snapshotPayload = [
                    'schema' => 'reconciliation-shadow.v1',
                    'enforcement' => 'off',
                    'company_id' => $companyId,
                    'period' => $snapshot['period'],
                    'algorithm_version' => self::ALGORITHM_VERSION,
                    'input_cutoff_at' => $cutoffAt->toISOString(),
                    'results' => $resultPayloads->map(fn (array $result): array => [
                        'check_code' => $result['check_code'],
                        'status' => $result['status'],
                        'fingerprint' => $result['fingerprint'],
                        'result_hash' => $result['result_hash'],
                    ])->all(),
                ];
                $snapshotHash = $this->hash($snapshotPayload);

                $statusCounts = $resultPayloads->countBy('status');
                $run = ReconciliationRun::withoutGlobalScope('company')->create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'period_id' => $period->id,
                    'basis' => 'shadow',
                    'status' => 'completed',
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'algorithm_version' => self::ALGORITHM_VERSION,
                    'input_cutoff_at' => $cutoffAt,
                    'started_at' => $startedAt,
                    'completed_at' => $completedAt,
                    'requested_by' => $actorId,
                    'posted_entry_count' => $snapshot['posted_entry_count'],
                    'posted_line_count' => $snapshot['posted_line_count'],
                    'total_debit' => $snapshot['total_debit'],
                    'total_credit' => $snapshot['total_credit'],
                    'result_count' => $resultPayloads->count(),
                    'failed_result_count' => $statusCounts->get('fail', 0),
                    'warning_result_count' => $statusCounts->get('warning', 0),
                    'not_available_result_count' => $statusCounts->get('not_available', 0),
                    'snapshot_hash' => $snapshotHash,
                    'snapshot' => $snapshotPayload,
                ]);

                foreach ($resultPayloads as $result) {
                    ReconciliationCheckResult::withoutGlobalScope('company')->create([
                        ...$result,
                        'company_id' => $companyId,
                        'reconciliation_run_id' => $run->id,
                    ]);
                }

                $this->auditService->record(
                    $run,
                    'reconciliation.shadow.completed',
                    [],
                    [
                        'status' => $run->status,
                        'snapshot_hash' => $run->snapshot_hash,
                        'result_count' => $run->result_count,
                        'failed_result_count' => $run->failed_result_count,
                        'not_available_result_count' => $run->not_available_result_count,
                    ],
                    metadata: [
                        'audit_schema' => 'reconciliation-shadow.v1',
                        'period_id' => $period->id,
                        'basis' => 'shadow',
                        'enforcement' => 'off',
                        'algorithm_version' => self::ALGORITHM_VERSION,
                    ]
                );

                return ['run' => $run->load('results'), 'replayed' => false];
            }, 3);
        } catch (QueryException $exception) {
            // A concurrent request may win the tenant/idempotency unique key.
            $winner = $this->findByIdempotencyKey($companyId, $idempotencyKey);
            if ($winner !== null) {
                return $this->replayOrConflict($winner, $requestHash);
            }

            throw $exception;
        }
    }

    public function findTenantRun(int $companyId, string $uuid): ReconciliationRun
    {
        $companyId = $this->requireCompanyId($companyId);
        $run = ReconciliationRun::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('uuid', $uuid)
            ->first();

        if ($run === null) {
            throw new NotFoundHttpException('Reconciliation run was not found.');
        }

        return $run;
    }

    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId < 1 || ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId)) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }

    /** @return array{run: ReconciliationRun, replayed: true} */
    private function replayOrConflict(ReconciliationRun $run, string $requestHash): array
    {
        if (! hash_equals($run->request_hash, $requestHash)) {
            throw new ConflictHttpException('The Idempotency-Key was already used for a different request.');
        }

        return ['run' => $run->loadMissing('results'), 'replayed' => true];
    }

    private function findByIdempotencyKey(int $companyId, string $key): ?ReconciliationRun
    {
        return ReconciliationRun::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('idempotency_key', $key)
            ->first();
    }

    /** @return array<string, mixed> */
    private function buildSnapshot(int $companyId, Period $period, Carbon $cutoffAt): array
    {
        $fiscalYear = $period->fiscalYear;
        $periodEntries = JournalEntry::withoutGlobalScope('company')
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->whereBetween('posting_date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
            ->where('updated_at', '<=', $cutoffAt)
            ->orderBy('id')
            ->get();
        $entryIds = $periodEntries->pluck('id');
        $lines = $entryIds->isEmpty()
            ? collect()
            : DB::table('journal_entry_lines')
                ->whereIn('journal_entry_id', $entryIds)
                ->where('updated_at', '<=', $cutoffAt)
                ->orderBy('id')
                ->get();

        $zero = BigDecimal::zero()->toScale(2);
        $totalDebit = $zero;
        $totalCredit = $zero;
        $invalidSideLineIds = [];
        $zeroLineIds = [];
        $lineTotalsByEntry = [];
        $lineCountsByEntry = [];
        foreach ($lines as $line) {
            $debit = $this->decimal((string) $line->debit_amount);
            $credit = $this->decimal((string) $line->credit_amount);
            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);
            $lineTotalsByEntry[$line->journal_entry_id]['debit'] = ($lineTotalsByEntry[$line->journal_entry_id]['debit'] ?? $zero)->plus($debit);
            $lineTotalsByEntry[$line->journal_entry_id]['credit'] = ($lineTotalsByEntry[$line->journal_entry_id]['credit'] ?? $zero)->plus($credit);
            $lineCountsByEntry[$line->journal_entry_id] = ($lineCountsByEntry[$line->journal_entry_id] ?? 0) + 1;

            if ($debit->isZero() && $credit->isZero()) {
                $zeroLineIds[] = $line->id;
            }
            if ($debit->isNegative() || $credit->isNegative()
                || ($debit->isPositive() && $credit->isPositive())
                || ($debit->isZero() && $credit->isZero())) {
                $invalidSideLineIds[] = $line->id;
            }
        }

        $emptyEntryIds = [];
        $unbalancedEntryIds = [];
        foreach ($periodEntries as $entry) {
            if (($lineCountsByEntry[$entry->id] ?? 0) === 0) {
                $emptyEntryIds[] = $entry->id;
            }
            $totals = $lineTotalsByEntry[$entry->id] ?? ['debit' => $zero, 'credit' => $zero];
            if (! $totals['debit']->isEqualTo($totals['credit'])) {
                $unbalancedEntryIds[] = $entry->id;
            }
        }

        $accountCodes = $lines->pluck('account_code')->filter(fn ($code) => is_string($code) && $code !== '')->unique()->values();
        $accounts = ChartOfAccount::withoutGlobalScope('company')
            ->withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('code', $accountCodes)
            ->get()
            ->keyBy('code');
        $missingAccountLineIds = [];
        $inactiveAccountLineIds = [];
        $parentAccountLineIds = [];
        foreach ($lines as $line) {
            $account = $accounts->get($line->account_code);
            if ($account === null) {
                $missingAccountLineIds[] = $line->id;
            } elseif (! $account->is_active || $account->trashed()) {
                $inactiveAccountLineIds[] = $line->id;
            } elseif ($account->is_parent) {
                $parentAccountLineIds[] = $line->id;
            }
        }

        $wrongFiscalYearEntryIds = $periodEntries
            ->where('fiscal_year_id', '!=', $fiscalYear->id)
            ->pluck('id')
            ->all();
        $outsideFiscalYearEntryIds = JournalEntry::withoutGlobalScope('company')
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('updated_at', '<=', $cutoffAt)
            ->where(function ($query) use ($fiscalYear): void {
                $query->whereDate('posting_date', '<', $fiscalYear->start_date->toDateString())
                    ->orWhereDate('posting_date', '>', $fiscalYear->end_date->toDateString());
            })
            ->pluck('id')
            ->all();
        $fiscalPeriods = Period::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->get(['id', 'start_date', 'end_date']);
        $fiscalYearEntries = JournalEntry::withoutGlobalScope('company')
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->where('fiscal_year_id', $fiscalYear->id)
            ->whereBetween('posting_date', [$fiscalYear->start_date->toDateString(), $fiscalYear->end_date->toDateString()])
            ->where('updated_at', '<=', $cutoffAt)
            ->get(['id', 'posting_date']);
        $outsideConfiguredPeriodEntryIds = [];
        $ambiguousPeriodEntryIds = [];
        foreach ($fiscalYearEntries as $entry) {
            $periodMembershipCount = $fiscalPeriods->filter(fn (Period $candidate): bool => $entry->posting_date->betweenIncluded($candidate->start_date, $candidate->end_date)
            )->count();
            if ($periodMembershipCount === 0) {
                $outsideConfiguredPeriodEntryIds[] = $entry->id;
            } elseif ($periodMembershipCount > 1) {
                $ambiguousPeriodEntryIds[] = $entry->id;
            }
        }
        $periodBoundsInvalid = $period->start_date->lt($fiscalYear->start_date)
            || $period->end_date->gt($fiscalYear->end_date)
            || $period->start_date->gt($period->end_date);

        $softDeletedPostedIds = $periodEntries->filter(fn (JournalEntry $entry) => $entry->trashed())->pluck('id')->all();
        [$duplicateSourceIds, $orphanSourceIds, $headerMismatchIds, $unsupportedTypes] =
            $this->sourceIntegrity($companyId, $periodEntries, $period, $cutoffAt);

        $results = [
            $this->result('GL.PERIOD_LEDGER_PRESENCE', $periodEntries->isEmpty() ? 'warning' : 'pass', [
                'posted_entry_count' => $periodEntries->count(),
                'meaning' => $periodEntries->isEmpty()
                    ? 'The selected period contains no posted journal entries; no policy conclusion was inferred.'
                    : 'Posted journal entries were available for structural checks.',
            ], $periodEntries->count()),
            $this->result('GL.AGGREGATE_BALANCE', $totalDebit->isEqualTo($totalCredit) ? 'pass' : 'fail', [
                'debit' => (string) $totalDebit,
                'credit' => (string) $totalCredit,
                'difference' => (string) $totalDebit->minus($totalCredit),
            ], $periodEntries->count(), (string) $totalDebit, (string) $totalCredit),
            $this->result('GL.ENTRY_BALANCE_AND_PRESENCE', ($emptyEntryIds === [] && $unbalancedEntryIds === []) ? 'pass' : 'fail', [
                'empty_entry_ids' => $this->limit($emptyEntryIds),
                'unbalanced_entry_ids' => $this->limit($unbalancedEntryIds),
            ], count(array_unique([...$emptyEntryIds, ...$unbalancedEntryIds]))),
            $this->result('GL.LINE_SIDE_VALIDITY', $invalidSideLineIds === [] ? 'pass' : 'fail', [
                'invalid_line_ids' => $this->limit($invalidSideLineIds),
                'zero_line_ids' => $this->limit($zeroLineIds),
            ], count($invalidSideLineIds)),
            $this->result('GL.ACCOUNT_VALIDITY', ($missingAccountLineIds === [] && $inactiveAccountLineIds === [] && $parentAccountLineIds === []) ? 'pass' : 'fail', [
                'missing_account_line_ids' => $this->limit($missingAccountLineIds),
                'inactive_account_line_ids' => $this->limit($inactiveAccountLineIds),
                'parent_account_line_ids' => $this->limit($parentAccountLineIds),
            ], count(array_unique([...$missingAccountLineIds, ...$inactiveAccountLineIds, ...$parentAccountLineIds]))),
            $this->result('GL.PERIOD_FISCAL_INTEGRITY', (! $periodBoundsInvalid
                && $wrongFiscalYearEntryIds === []
                && $outsideFiscalYearEntryIds === []
                && $outsideConfiguredPeriodEntryIds === []
                && $ambiguousPeriodEntryIds === []) ? 'pass' : 'fail', [
                    'period_bounds_outside_fiscal_year' => $periodBoundsInvalid,
                    'wrong_fiscal_year_entry_ids' => $this->limit($wrongFiscalYearEntryIds),
                    'outside_fiscal_year_entry_ids' => $this->limit($outsideFiscalYearEntryIds),
                    'outside_configured_period_entry_ids' => $this->limit($outsideConfiguredPeriodEntryIds),
                    'ambiguous_period_entry_ids' => $this->limit($ambiguousPeriodEntryIds),
                ], count(array_unique([
                    ...$wrongFiscalYearEntryIds,
                    ...$outsideFiscalYearEntryIds,
                    ...$outsideConfiguredPeriodEntryIds,
                    ...$ambiguousPeriodEntryIds,
                ])) + ($periodBoundsInvalid ? 1 : 0)),
            $this->result('GL.SOFT_DELETED_POSTED', $softDeletedPostedIds === [] ? 'pass' : 'fail', [
                'soft_deleted_posted_entry_ids' => $this->limit($softDeletedPostedIds),
            ], count($softDeletedPostedIds)),
            $this->result('SOURCE.DUPLICATE_JE_LINK', $duplicateSourceIds === [] ? 'pass' : 'fail', [
                'duplicates' => $this->limit($duplicateSourceIds),
            ], count($duplicateSourceIds)),
            $this->result('SOURCE.ORPHAN_JE_LINK', $orphanSourceIds === [] ? 'pass' : 'fail', [
                'orphan_links' => $this->limit($orphanSourceIds),
            ], count($orphanSourceIds)),
            $this->result('SOURCE.HEADER_JE_MISMATCH', $headerMismatchIds === [] ? 'pass' : 'fail', [
                'mismatches' => $this->limit($headerMismatchIds),
            ], count($headerMismatchIds)),
            $this->result('SOURCE.UNSUPPORTED_MODELS', $unsupportedTypes === [] ? 'pass' : 'not_available', [
                'unsupported_source_types' => $this->limit($unsupportedTypes),
                'meaning' => 'No linkage assumption was made for these source model types.',
            ], count($unsupportedTypes)),
        ];

        return [
            'period' => [
                'id' => $period->id,
                'fiscal_year_id' => $fiscalYear->id,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
            ],
            'posted_entry_count' => $periodEntries->count(),
            'posted_line_count' => $lines->count(),
            'total_debit' => (string) $totalDebit,
            'total_credit' => (string) $totalCredit,
            'results' => $results,
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: list<string>}
     */
    private function sourceIntegrity(int $companyId, Collection $periodEntries, Period $period, Carbon $cutoffAt): array
    {
        $registry = $this->sourceRegistry();
        $duplicates = [];
        $orphans = [];
        $mismatches = [];
        $unsupported = [];

        $linkedEntries = $periodEntries->filter(fn (JournalEntry $entry) => $entry->source_document_type !== null && $entry->source_document_id !== null
        );
        $periodSourceKeys = $linkedEntries
            ->map(fn (JournalEntry $entry): string => $entry->source_document_type.'#'.$entry->source_document_id)
            ->unique();
        $allActiveLinkedEntries = $periodSourceKeys->isEmpty()
            ? collect()
            : JournalEntry::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('status', 'posted')
                ->whereNull('deleted_at')
                ->whereNotNull('source_document_type')
                ->whereNotNull('source_document_id')
                ->where('updated_at', '<=', $cutoffAt)
                ->get()
                ->filter(fn (JournalEntry $entry): bool => $periodSourceKeys->contains(
                    $entry->source_document_type.'#'.$entry->source_document_id
                ));
        foreach ($allActiveLinkedEntries->groupBy(fn (JournalEntry $entry): string => $entry->source_document_type.'#'.$entry->source_document_id) as $key => $entries) {
            if ($entries->count() > 1) {
                $duplicates[] = ['source_key' => $key, 'journal_entry_ids' => $entries->pluck('id')->all()];
            }
        }

        foreach ($linkedEntries as $entry) {
            $definition = $registry[$entry->source_document_type] ?? null;
            if ($definition === null) {
                $unsupported[] = $entry->source_document_type;

                continue;
            }

            if (! Schema::hasTable($definition['table'])) {
                $unsupported[] = $entry->source_document_type;

                continue;
            }

            $sourceQuery = DB::table($definition['table'])->where('id', $entry->source_document_id);
            if (Schema::hasColumn($definition['table'], 'updated_at')) {
                $sourceQuery->where('updated_at', '<=', $cutoffAt);
            }
            $source = $sourceQuery->first();
            if ($source === null
                || (property_exists($source, 'company_id') && (int) $source->company_id !== $companyId)
                || (property_exists($source, 'deleted_at') && $source->deleted_at !== null)) {
                $orphans[] = [
                    'journal_entry_id' => $entry->id,
                    'source_type' => $entry->source_document_type,
                    'source_id' => $entry->source_document_id,
                ];

                continue;
            }

            if ($definition['header_link'] && (int) ($source->journal_entry_id ?? 0) !== (int) $entry->id) {
                $mismatches[] = [
                    'direction' => 'journal_to_source',
                    'journal_entry_id' => $entry->id,
                    'source_type' => $entry->source_document_type,
                    'source_id' => $entry->source_document_id,
                    'header_journal_entry_id' => $source->journal_entry_id ?? null,
                ];
            }
        }

        foreach ($registry as $sourceType => $definition) {
            if (! $definition['header_link'] || ! Schema::hasTable($definition['table'])
                || ! Schema::hasColumn($definition['table'], 'company_id')
                || ! Schema::hasColumn($definition['table'], 'journal_entry_id')
                || ! Schema::hasColumn($definition['table'], $definition['date_column'])) {
                continue;
            }

            $query = DB::table($definition['table'])
                ->where('company_id', $companyId)
                ->whereNotNull('journal_entry_id');
            if (($definition['date_kind'] ?? 'date') === 'month') {
                $query->whereBetween($definition['date_column'], [
                    $period->start_date->format('Y-m'),
                    $period->end_date->format('Y-m'),
                ]);
            } else {
                $query->whereBetween($definition['date_column'], [
                    $period->start_date->toDateString(),
                    $period->end_date->toDateString(),
                ]);
            }
            if (Schema::hasColumn($definition['table'], 'updated_at')) {
                $query->where('updated_at', '<=', $cutoffAt);
            }
            if (Schema::hasColumn($definition['table'], 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            foreach ($query->get(['id', 'journal_entry_id']) as $source) {
                // A corrupt source pointer must not make this tenant-bound
                // report load or disclose a journal header from another
                // company.  Keep the mismatch evidence, but resolve the
                // pointed entry only inside the reconciliation tenant.
                $entry = JournalEntry::withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->withTrashed()
                    ->find($source->journal_entry_id);
                if ($entry === null || $entry->company_id !== $companyId
                    || $entry->status !== 'posted'
                    || $entry->trashed()
                    || $entry->posting_date->lt($period->start_date)
                    || $entry->posting_date->gt($period->end_date)
                    || $entry->source_document_type !== $sourceType
                    || (int) $entry->source_document_id !== (int) $source->id) {
                    $mismatches[] = [
                        'direction' => 'source_to_journal',
                        'journal_entry_id' => $source->journal_entry_id,
                        'source_type' => $sourceType,
                        'source_id' => $source->id,
                        'journal_source_type' => $entry?->source_document_type,
                        'journal_source_id' => $entry?->source_document_id,
                    ];
                }
            }
        }

        return [
            array_values($duplicates),
            array_values($orphans),
            array_values($mismatches),
            array_values(array_unique($unsupported)),
        ];
    }

    /** @return array<string, array{table: string, date_column: string, date_kind?: string, header_link: bool}> */
    private function sourceRegistry(): array
    {
        $definitions = [
            CashReceipt::class => ['date_column' => 'posting_date', 'header_link' => true],
            CashPayment::class => ['date_column' => 'posting_date', 'header_link' => true],
            BankReceipt::class => ['date_column' => 'posting_date', 'header_link' => true],
            BankPayment::class => ['date_column' => 'posting_date', 'header_link' => true],
            InventoryReceipt::class => ['date_column' => 'posting_date', 'header_link' => true],
            InventoryIssue::class => ['date_column' => 'posting_date', 'header_link' => true],
            PurchaseInvoice::class => ['date_column' => 'accounting_date', 'header_link' => true],
            PurchaseReturn::class => ['date_column' => 'accounting_date', 'header_link' => true],
            PurchaseDiscount::class => ['date_column' => 'accounting_date', 'header_link' => true],
            SalesInvoice::class => ['date_column' => 'accounting_date', 'header_link' => true],
            SalesReturn::class => ['date_column' => 'accounting_date', 'header_link' => true],
            SalesDiscount::class => ['date_column' => 'accounting_date', 'header_link' => true],
            Payroll::class => ['date_column' => 'posting_date', 'header_link' => true],
            FixedAsset::class => ['date_column' => 'voucher_date', 'header_link' => true],
            DepreciationLog::class => ['date_column' => 'accounting_date', 'header_link' => true],
            AssetDisposal::class => ['date_column' => 'accounting_date', 'header_link' => true],
            AssetRevaluation::class => ['date_column' => 'accounting_date', 'header_link' => true],
            AllocationLog::class => ['date_column' => 'month', 'date_kind' => 'month', 'header_link' => true],
            JournalEntry::class => ['date_column' => 'posting_date', 'header_link' => false],
        ];

        $registry = [];
        foreach ($definitions as $modelClass => $definition) {
            $model = new $modelClass;
            $registry[$model->getMorphClass()] = [
                'table' => $model->getTable(),
                ...$definition,
            ];
        }

        return $registry;
    }

    /** @return array<string, mixed> */
    private function result(
        string $checkCode,
        string $status,
        array $evidence,
        int $rowCount,
        ?string $leftTotal = null,
        ?string $rightTotal = null
    ): array {
        $evidence = ['enforcement' => 'off', ...$evidence];
        $fingerprint = $this->hash(['check_code' => $checkCode, 'evidence' => $evidence]);
        $difference = ($leftTotal !== null && $rightTotal !== null)
            ? (string) $this->decimal($leftTotal)->minus($this->decimal($rightTotal))
            : null;
        $payload = [
            'check_code' => $checkCode,
            'domain' => str_starts_with($checkCode, 'SOURCE.') ? 'source_linkage' : 'gl',
            'status' => $status,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'left_total' => $leftTotal,
            'right_total' => $rightTotal,
            'difference' => $difference,
            'row_count' => $rowCount,
            'evidence' => $evidence,
            'fingerprint' => $fingerprint,
        ];

        return [...$payload, 'result_hash' => $this->hash($payload)];
    }

    /** @param array<mixed> $values @return array<mixed> */
    private function limit(array $values): array
    {
        return array_slice($values, 0, self::EVIDENCE_LIMIT);
    }

    private function decimal(string $amount): BigDecimal
    {
        return BigDecimal::of(trim($amount))->toScale(2, RoundingMode::UNNECESSARY);
    }

    private function hash(array $payload): string
    {
        $this->sortRecursively($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function sortRecursively(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
        unset($item);

        if (! array_is_list($value)) {
            ksort($value);
        }
    }
}
