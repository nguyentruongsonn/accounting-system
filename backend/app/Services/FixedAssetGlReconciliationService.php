<?php

namespace App\Services;

use App\Models\AssetDisposal;
use App\Models\AssetRevaluation;
use App\Models\DepreciationLog;
use App\Models\FixedAsset;
use App\Models\FixedAssetGlReconciliationException;
use App\Models\FixedAssetGlReconciliationRun;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Captures source-to-posted-journal lineage evidence for the fixed-asset
 * subledger. It deliberately does not calculate an asset, accumulated
 * depreciation, revaluation, disposal, GL balance, or variance.
 *
 * A complete reducer and effective account mapping must be separately
 * approved before such a calculation can be safely implemented.
 */
final class FixedAssetGlReconciliationService
{
    public const ALGORITHM_VERSION = 'fixed-asset-subledger-gl-capability.v1';

    public function capture(User $actor, string $asOfDate): FixedAssetGlReconciliationRun
    {
        $companyId = (int) $actor->company_id;
        if ($companyId < 1) throw new AuthorizationException('An actor company is required.');
        try { $cutoff = CarbonImmutable::parse($asOfDate)->toDateString(); } catch (\Throwable) { throw new InvalidArgumentException('As-of date is invalid.'); }

        $lineage = $this->postedSourceLineage($companyId, $cutoff);
        $completeness = $this->sourceCompleteness($lineage);
        $missing = array_values(array_filter($completeness, static fn (array $item): bool => ! $item['available']));
        $snapshot = [
            'schema' => self::ALGORITHM_VERSION,
            'as_of_date' => $cutoff,
            'status' => 'not_available',
            'source_completeness' => $completeness,
            // Values must remain null until a complete, approved reducer
            // proves how every lifecycle movement maps to TSCĐ control GL.
            'amounts' => null,
            'statement' => 'No fixed-asset subledger balance, GL balance, or difference has been calculated by this foundation.',
        ];
        $contractHash = $this->hash([
            'schema' => self::ALGORITHM_VERSION,
            'required_contracts' => ['approved_fixed_asset_lifecycle_reducer', 'approved_effective_control_account_mapping', 'approved_cutoff_and_exception_policy'],
            'source_types' => [FixedAsset::class, DepreciationLog::class, AssetDisposal::class, AssetRevaluation::class],
            'lineage_rules' => ['posted_source', 'source_date_at_or_before_cutoff', 'posted_journal', 'matching_source_document_identity'],
        ]);

        return DB::transaction(function () use ($actor, $companyId, $cutoff, $completeness, $missing, $snapshot, $contractHash): FixedAssetGlReconciliationRun {
            $run = FixedAssetGlReconciliationRun::withoutGlobalScope('company')->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'as_of_date' => $cutoff,
                'status' => 'not_available', 'algorithm_version' => self::ALGORITHM_VERSION, 'contract_hash' => $contractHash,
                'source_completeness' => $completeness, 'divergence_count' => count($missing), 'snapshot' => $snapshot,
                'snapshot_hash' => $this->hash($snapshot), 'requested_by' => $actor->id, 'recorded_at' => now(),
            ]);
            foreach ($missing as $condition) {
                FixedAssetGlReconciliationException::withoutGlobalScope('company')->create([
                    'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'reconciliation_run_id' => $run->id,
                    'exception_code' => $condition['code'], 'severity' => 'blocking', 'reason' => $condition['reason'],
                    'evidence' => ['as_of_date' => $cutoff, 'contract_hash' => $contractHash, 'observed' => $condition['observed'] ?? null, 'required_tables' => $condition['required_tables'] ?? null],
                    'recorded_at' => now(),
                ]);
            }
            app(AuditService::class)->record($run, 'fixed_asset_gl_reconciliation.captured', [], [
                'as_of_date' => $cutoff, 'status' => 'not_available', 'blocking_conditions' => array_column($missing, 'code'),
            ], metadata: ['algorithm_version' => self::ALGORITHM_VERSION, 'side_effects' => 'none', 'amounts_calculated' => false]);
            return $run->load('exceptions');
        }, 3);
    }

    /** @return array<string,array{available:bool,code:string,reason:string,observed:array<string,mixed>}> */
    private function sourceCompleteness(array $lineage): array
    {
        $schema = Schema::hasTable('fixed_assets') && Schema::hasColumns('fixed_assets', ['company_id', 'id', 'is_posted', 'voucher_date', 'journal_entry_id'])
            && Schema::hasTable('asset_depreciation_logs') && Schema::hasColumns('asset_depreciation_logs', ['company_id', 'id', 'is_posted', 'accounting_date', 'journal_entry_id'])
            && Schema::hasTable('asset_disposals') && Schema::hasColumns('asset_disposals', ['company_id', 'id', 'is_posted', 'accounting_date', 'journal_entry_id'])
            && Schema::hasTable('asset_revaluations') && Schema::hasColumns('asset_revaluations', ['company_id', 'id', 'is_posted', 'accounting_date', 'journal_entry_id'])
            && Schema::hasTable('journal_entries') && Schema::hasColumns('journal_entries', ['company_id', 'id', 'status', 'source_document_type', 'source_document_id']);

        return [
            'lifecycle_and_gl_source_schema' => $this->condition($schema, 'fixed_asset_gl_source_schema_unavailable', 'The fixed-asset lifecycle or posted-journal source-lineage schema is unavailable.', ['schema_available' => $schema]),
            'posted_lifecycle_journal_lineage' => $this->condition($lineage['invalid_count'] === 0, 'fixed_asset_posted_lifecycle_lineage_unproven', 'At least one posted fixed-asset lifecycle source at the cutoff has no accounting date or lacks a matching posted journal source lineage.', $lineage),
            'approved_lifecycle_reducer' => $this->condition(false, 'fixed_asset_lifecycle_reducer_not_owner_approved', 'No owner-approved reducer proves the treatment of opening balances, additions, depreciation, disposals, revaluations, corrections and reversals at one cutoff.', ['source_counts' => $lineage['source_counts']]),
            'effective_control_account_mapping' => $this->condition(false, 'fixed_asset_control_account_mapping_not_owner_approved', 'No effective-dated owner-approved mapping proves which asset, accumulated depreciation, revaluation, gain/loss, and disposal GL accounts form this subledger tie-out.', ['required_account_roles' => ['asset', 'accumulated_depreciation', 'revaluation', 'gain_loss_disposal']]),
            'cutoff_and_exception_policy' => $this->condition(false, 'fixed_asset_gl_cutoff_exception_policy_not_owner_approved', 'No owner-approved cutoff, opening-balance, exception, waiver, and sign-off policy exists; this capture cannot assert a tie-out or close readiness.', ['as_of_date' => $lineage['as_of_date']]),
        ];
    }

    /** @return array{as_of_date:string,source_counts:array<string,int>,valid_count:int,invalid_count:int,date_unproven_count:int,invalid_sources:list<array{type:string,id:int,journal_entry_id:?int,reason:string}>} */
    private function postedSourceLineage(int $companyId, string $cutoff): array
    {
        $definitions = [
            'increment' => [FixedAsset::class, 'voucher_date'],
            'depreciation' => [DepreciationLog::class, 'accounting_date'],
            'disposal' => [AssetDisposal::class, 'accounting_date'],
            'revaluation' => [AssetRevaluation::class, 'accounting_date'],
        ];
        $counts = array_fill_keys(array_keys($definitions), 0);
        $valid = 0; $invalid = []; $dateUnproven = 0;
        foreach ($definitions as $label => [$class, $dateColumn]) {
            /** @var class-string<Model> $class */
            // A posted lifecycle event without an accounting date cannot be
            // silently omitted from an as-of reconciliation: it could belong
            // on either side of the cutoff.
            $sources = $class::withoutGlobalScope('company')->where('company_id', $companyId)->where('is_posted', true)->get(['id', 'journal_entry_id', $dateColumn]);
            $eligible = $sources->filter(function (Model $source) use ($dateColumn, $cutoff, &$invalid, &$dateUnproven, $label): bool {
                $value = $source->getAttribute($dateColumn);
                if ($value === null || $value === '') {
                    $dateUnproven++;
                    $invalid[] = ['type' => $label, 'id' => (int) $source->id, 'journal_entry_id' => $source->journal_entry_id === null ? null : (int) $source->journal_entry_id, 'reason' => 'missing_accounting_date'];
                    return false;
                }
                return CarbonImmutable::parse((string) $value)->toDateString() <= $cutoff;
            });
            $counts[$label] = $eligible->count();
            foreach ($eligible as $source) {
                if ($this->hasMatchingPostedJournal($companyId, $class, (int) $source->id, $source->journal_entry_id === null ? null : (int) $source->journal_entry_id)) $valid++;
                else $invalid[] = ['type' => $label, 'id' => (int) $source->id, 'journal_entry_id' => $source->journal_entry_id === null ? null : (int) $source->journal_entry_id, 'reason' => 'posted_journal_lineage_unproven'];
            }
        }
        return ['as_of_date' => $cutoff, 'source_counts' => $counts, 'valid_count' => $valid, 'invalid_count' => count($invalid), 'date_unproven_count' => $dateUnproven, 'invalid_sources' => $invalid];
    }

    private function hasMatchingPostedJournal(int $companyId, string $class, int $sourceId, ?int $journalEntryId): bool
    {
        if ($journalEntryId === null) return false;
        return JournalEntry::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($journalEntryId)
            ->where('status', 'posted')->where('source_document_type', $class)->where('source_document_id', $sourceId)->exists();
    }

    /** @param array<string,mixed> $observed @return array{available:bool,code:string,reason:string,observed:array<string,mixed>} */
    private function condition(bool $available, string $code, string $reason, array $observed): array { return compact('available', 'code', 'reason', 'observed'); }
    private function hash(array $payload): string { $this->sort($payload); return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
    private function sort(array &$value): void { foreach ($value as &$item) if (is_array($item)) $this->sort($item); unset($item); if (! array_is_list($value)) ksort($value); }
}
