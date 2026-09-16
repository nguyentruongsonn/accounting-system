<?php

namespace App\Services;

use App\Models\ApArSubledgerGlReconciliationException;
use App\Models\ApArSubledgerGlReconciliationRun;
use App\Models\BankAccount;
use App\Models\BankGlReconciliationException;
use App\Models\BankGlReconciliationRun;
use App\Models\FixedAssetGlReconciliationException;
use App\Models\FixedAssetGlReconciliationRun;
use App\Models\InventorySubledgerGlReconciliationException;
use App\Models\InventorySubledgerGlReconciliationRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Read-only selector for controlled subledger-to-GL close evidence.
 *
 * This is deliberately not a reconciliation calculator, approval workflow,
 * or close permission.  It only proves whether four independently captured
 * domains supplied a compatible, approved result at one tenant/cutoff.  A
 * missing contract is reported as unavailable rather than being filled with
 * a previous run, a run from another tenant, or a zero variance.
 */
final class ControlledPeriodCloseReconciliationAggregator
{
    public const SCHEMA = 'controlled-period-close-reconciliation.v1';

    /** @return array<string,mixed> */
    public function evaluate(int $companyId, string $cutoff): array
    {
        // This read-only selector is callable outside HTTP controllers. Keep
        // explicit unauthenticated worker calls compatible, but do not allow
        // an authenticated actor to inspect another tenant's close evidence.
        $companyId = $this->requireCompanyId($companyId);
        $cutoff = CarbonImmutable::parse($cutoff)->toDateString();

        $ap = $this->latest(ApArSubledgerGlReconciliationRun::class, $companyId, $cutoff, ['ledger' => 'ap']);
        $ar = $this->latest(ApArSubledgerGlReconciliationRun::class, $companyId, $cutoff, ['ledger' => 'ar']);
        $inventory = $this->latest(InventorySubledgerGlReconciliationRun::class, $companyId, $cutoff);
        $fixedAsset = $this->latest(FixedAssetGlReconciliationRun::class, $companyId, $cutoff);

        $checks = [
            $this->evaluateRun('AP_SUBLEDGER_GL', $ap, ApArSubledgerGlReconciliationException::class, $companyId, $cutoff),
            $this->evaluateRun('AR_SUBLEDGER_GL', $ar, ApArSubledgerGlReconciliationException::class, $companyId, $cutoff),
            $this->evaluateApArContractCompatibility($ap, $ar, $cutoff),
            $this->evaluateRun('INVENTORY_SUBLEDGER_GL', $inventory, InventorySubledgerGlReconciliationException::class, $companyId, $cutoff),
            $this->evaluateRun('FIXED_ASSET_GL', $fixedAsset, FixedAssetGlReconciliationException::class, $companyId, $cutoff),
            $this->evaluateBankDomain($companyId, $cutoff),
        ];
        $eligible = collect($checks)->every(static fn (array $check): bool => $check['status'] === 'pass');

        return [
            'schema' => self::SCHEMA,
            'company_id' => $companyId,
            'cutoff' => $cutoff,
            'status' => $eligible ? 'controlled_reconciled' : 'not_available',
            'eligible_to_close' => $eligible,
            'statement' => $eligible
                ? 'All required reconciliation domains supplied compatible approved controlled evidence at this tenant and cutoff. Independent close sign-off and period-close controls are still required.'
                : 'This is not close authority. At least one required reconciliation domain lacks compatible approved controlled evidence at this tenant and cutoff.',
            'checks' => $checks,
        ];
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

    private function latest(string $model, int $companyId, string $cutoff, array $extra = []): ?Model
    {
        $query = $model::withoutGlobalScope('company')->where('company_id', $companyId)->whereDate('as_of_date', $cutoff);
        foreach ($extra as $column => $value) $query->where($column, $value);
        return $query->latest('recorded_at')->latest('id')->first();
    }

    /** @return array<string,mixed> */
    private function evaluateRun(string $code, ?Model $run, string $exceptionModel, int $companyId, string $cutoff): array
    {
        if ($run === null) {
            return $this->unavailable($code, 'missing_same_cutoff_run', 'No reconciliation run exists for this tenant and cutoff.', ['required_cutoff' => $cutoff]);
        }

        $reasons = $this->approvalFailures($run, $cutoff);
        $exceptionCount = $exceptionModel::withoutGlobalScope('company')
            ->where('company_id', $companyId)->where('reconciliation_run_id', $run->id)->count();
        if ($exceptionCount > 0) $reasons[] = 'reconciliation_exceptions_present';

        if ($reasons !== []) {
            return $this->unavailable($code, 'run_not_approved_controlled_result', 'The latest same-cutoff run is not a clean approved controlled result.', [
                'run_id' => $run->id, 'run_uuid' => $run->uuid, 'run_status' => $run->status,
                'reasons' => array_values(array_unique($reasons)), 'exception_count' => $exceptionCount,
            ]);
        }

        return ['code' => $code, 'status' => 'pass', 'details' => [
            'run_id' => $run->id, 'run_uuid' => $run->uuid, 'as_of_date' => $run->as_of_date?->toDateString(),
            'snapshot_hash' => $run->snapshot_hash, 'contract_hash' => $run->contract_hash,
        ]];
    }

    /** @return array<string,mixed> */
    private function evaluateApArContractCompatibility(?Model $ap, ?Model $ar, string $cutoff): array
    {
        if ($ap === null || $ar === null) {
            return $this->unavailable(
                'AP_AR_CONTRACT_COMPATIBILITY',
                'missing_same_cutoff_run',
                'Both AP and AR controlled runs are required before contract compatibility can be evaluated.',
                ['required_cutoff' => $cutoff],
            );
        }

        $apAlgorithm = (string) ($ap->algorithm_version ?? '');
        $arAlgorithm = (string) ($ar->algorithm_version ?? '');
        if ($apAlgorithm === '' || $arAlgorithm === '' || ! hash_equals($apAlgorithm, $arAlgorithm)) {
            return $this->unavailable(
                'AP_AR_CONTRACT_COMPATIBILITY',
                'contract_family_mismatch',
                'AP and AR runs at the same cutoff use different or missing reducer contract families.',
                [
                    'required_cutoff' => $cutoff,
                    'ap_algorithm_version' => $apAlgorithm !== '' ? $apAlgorithm : null,
                    'ar_algorithm_version' => $arAlgorithm !== '' ? $arAlgorithm : null,
                ],
            );
        }

        return [
            'code' => 'AP_AR_CONTRACT_COMPATIBILITY',
            'status' => 'pass',
            'details' => [
                'cutoff' => $cutoff,
                'algorithm_version' => $apAlgorithm,
                // Contract hashes remain ledger-specific evidence; the
                // family-version check above intentionally does not require
                // AP and AR hashes to be identical.
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function evaluateBankDomain(int $companyId, string $cutoff): array
    {
        $accounts = BankAccount::withoutGlobalScope('company')->where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get(['id']);
        if ($accounts->isEmpty()) {
            return $this->unavailable('BANK_GL', 'approved_bank_scope_missing', 'No active bank-account scope is available; an empty scope cannot be assumed reconciled.', ['required_cutoff' => $cutoff]);
        }

        $items = [];
        foreach ($accounts as $account) {
            $run = $this->latest(BankGlReconciliationRun::class, $companyId, $cutoff, ['bank_account_id' => $account->id]);
            $item = $this->evaluateRun('BANK_GL_ACCOUNT', $run, BankGlReconciliationException::class, $companyId, $cutoff);
            $item['details']['bank_account_id'] = $account->id;
            $items[] = $item;
        }
        $failed = array_values(array_filter($items, static fn (array $item): bool => $item['status'] !== 'pass'));
        if ($failed !== []) {
            return $this->unavailable('BANK_GL', 'bank_account_result_missing_or_unapproved', 'Every active bank account must supply approved controlled evidence at the same cutoff.', ['accounts' => $items]);
        }
        return ['code' => 'BANK_GL', 'status' => 'pass', 'details' => ['accounts' => $items]];
    }

    /** @return list<string> */
    private function approvalFailures(Model $run, string $cutoff): array
    {
        $snapshot = is_array($run->snapshot) ? $run->snapshot : [];
        $control = is_array(data_get($snapshot, 'control')) ? data_get($snapshot, 'control') : [];
        $completeness = is_array($run->source_completeness) ? $run->source_completeness : [];
        $allSourcesAvailable = $completeness !== [] && collect($completeness)->every(static fn ($item): bool => is_array($item) && ($item['available'] ?? false) === true);

        $failures = [];
        if ($run->as_of_date?->toDateString() !== $cutoff || data_get($snapshot, 'as_of_date') !== $cutoff) $failures[] = 'cutoff_mismatch';
        if ($run->status !== 'approved') $failures[] = 'run_status_not_approved';
        if (data_get($snapshot, 'status') !== 'reconciled') $failures[] = 'snapshot_not_reconciled';
        if (($control['mode'] ?? null) !== 'controlled') $failures[] = 'control_mode_not_controlled';
        if (($control['eligible_for_period_close'] ?? false) !== true) $failures[] = 'control_not_marked_eligible_for_period_close';
        if (! is_string($control['approved_at'] ?? null) || $control['approved_at'] === '') $failures[] = 'approval_timestamp_missing';
        if ((int) $run->divergence_count !== 0) $failures[] = 'divergences_present';
        if (! $allSourcesAvailable) $failures[] = 'source_completeness_not_proven';
        if (! is_string($run->snapshot_hash) || strlen($run->snapshot_hash) !== 64 || ! is_string($run->contract_hash) || strlen($run->contract_hash) !== 64) $failures[] = 'immutable_hash_evidence_invalid';
        return $failures;
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    private function unavailable(string $code, string $reasonCode, string $reason, array $details): array
    {
        return ['code' => $code, 'status' => 'not_available', 'details' => $details + ['reason_code' => $reasonCode, 'reason' => $reason]];
    }
}
