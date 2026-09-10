<?php

namespace App\Services;

use App\Models\ApArSubledgerGlReconciliationException;
use App\Models\ApArSubledgerGlReconciliationRun;
use App\Models\AccountingPolicyVersion;
use App\Models\OpeningBalancePackage;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Captures immutable evidence for AP/AR subledger-to-GL tie-out.
 *
 * Without an owner-approved reducer contract it remains a capability capture
 * and never infers an account from voucher text. With the explicit contract,
 * it calculates a functional-currency tie-out from posted source evidence.
 */
final class ApArSubledgerGlReconciliationService
{
    public const ALGORITHM_VERSION = 'apar-subledger-gl-capability.v2-input-boundary';
    public const CONTROLLED_ALGORITHM_VERSION = 'apar-subledger-gl-controlled.v1';
    private const POLICY_KEY = 'reconciliation.ap_ar';

    public function capture(User $actor, string $ledger, string $asOfDate): ApArSubledgerGlReconciliationRun
    {
        $companyId = (int) $actor->company_id;
        if ($companyId < 1) throw new AuthorizationException('An actor company is required.');
        if (! in_array($ledger, ['ap', 'ar'], true)) throw new InvalidArgumentException('AP/AR ledger must be ap or ar.');
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $asOfDate);
            $errors = CarbonImmutable::getLastErrors();
            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->toDateString() !== $asOfDate) throw new \RuntimeException();
            $cutoff = $parsed->toDateString();
        } catch (\Throwable) { throw new InvalidArgumentException('As-of date must be an unambiguous YYYY-MM-DD calendar date.'); }

        $policy = $this->approvedPolicy($companyId, $cutoff);
        $contract = $this->reconciliationContract($policy, $ledger);
        $completeness = $this->sourceCompleteness($ledger, $contract !== null, $this->controlMappingAvailable($companyId, $contract, $ledger));
        $missing = array_values(array_filter($completeness, static fn (array $item): bool => ! $item['available']));
        // This capability foundation has no approved open-item reducer or
        // control-account mapping, even if all physical tables happen to exist.
        $physicalSourcesAvailable = ($completeness['open_item_source']['available'] ?? false)
            && ($completeness['settlement_source']['available'] ?? false)
            && ($completeness['gl_source_lineage']['available'] ?? false);
        return $this->captureInConsistentSnapshot($actor, $companyId, $ledger, $cutoff, $completeness, $missing, $physicalSourcesAvailable, $policy, $contract);
    }

    /**
     * Capture a source boundary, not source amounts or memberships.  The
     * transaction pins every metadata query to one database read snapshot;
     * unsupported drivers deliberately receive a null watermark instead.
     */
    private function captureInConsistentSnapshot(User $actor, int $companyId, string $ledger, string $cutoff, array $completeness, array $missing, bool $physicalSourcesAvailable, ?AccountingPolicyVersion $policy, ?array $contract): ApArSubledgerGlReconciliationRun
    {
        $driver = DB::connection()->getDriverName();
        $canSnapshot = $physicalSourcesAvailable && in_array($driver, ['sqlite', 'mysql', 'mariadb'], true);

        // MySQL only accepts `SET TRANSACTION` before the transaction starts.
        // If this service is called from an existing transaction, claiming a
        // repeatable-read boundary would be false; record an unavailable
        // boundary instead and preserve the capture-only fail-closed contract.
        if ($canSnapshot && in_array($driver, ['mysql', 'mariadb'], true) && DB::transactionLevel() > 0) {
            $canSnapshot = false;
        }

        if ($canSnapshot && in_array($driver, ['mysql', 'mariadb'], true)) {
            // Applies to the next transaction only.  Do not rely on a server
            // default that might be READ COMMITTED in a production tenant.
            DB::unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction(function () use ($actor, $companyId, $ledger, $cutoff, $completeness, $missing, $physicalSourcesAvailable, $driver, $canSnapshot, $policy, $contract): ApArSubledgerGlReconciliationRun {
            // Capture the watermark only after entering the read transaction,
            // immediately before the first source query establishes its view.
            $inputCutoffAt = now('UTC')->toImmutable();
            $boundary = $canSnapshot
                ? $this->inputBoundary($companyId, $ledger, $cutoff, $inputCutoffAt)
                : $this->unavailableInputBoundary($companyId, $ledger, $cutoff, $physicalSourcesAvailable ? $driver : 'source_schema_unavailable');
            if (! $canSnapshot) {
                $missing[] = $this->condition(false, 'apar_input_boundary_snapshot_not_supported', $physicalSourcesAvailable ? "Database driver [{$driver}] cannot prove one consistent AP/AR input-boundary snapshot." : 'Required AP/AR source schema is unavailable, so no cutoff watermark was captured.', []);
            }
            $controlled = null;
            if ($contract !== null && $missing === [] && $canSnapshot) {
                $controlled = $this->calculateControlled($companyId, $ledger, $cutoff, $inputCutoffAt, $contract, $policy);
                $missing = array_merge($missing, $controlled['exceptions']);
            }
            $isControlled = $controlled !== null;
            $status = $isControlled
                ? ($missing === [] ? 'approved' : 'blocked')
                : 'not_available';
            $snapshot = $isControlled ? $controlled['snapshot'] : [
                'schema' => self::ALGORITHM_VERSION,
                'ledger' => $ledger,
                'as_of_date' => $cutoff,
                'status' => 'not_available',
                'source_completeness' => $completeness,
                'input_boundary' => $boundary,
                'amounts' => null,
                'amounts_calculated' => false,
                'tie_out_calculated' => false,
                'close_authority' => false,
                'statement' => 'This is immutable input-boundary evidence only. No AP/AR balance, 131/331 tie-out, waiver, approval, or close authority has been calculated.',
            ];
            if ($isControlled) {
                $snapshot['status'] = $status === 'approved' ? 'reconciled' : 'blocked';
                $snapshot['control']['eligible_for_period_close'] = $status === 'approved';
            }
            $algorithmVersion = $isControlled ? self::CONTROLLED_ALGORITHM_VERSION : self::ALGORITHM_VERSION;
            $contractHash = $this->hash(['schema' => $algorithmVersion, 'ledger' => $ledger, 'source_completeness' => $completeness, 'policy_id' => $policy?->id, 'policy_contract_hash' => $policy?->contract_hash]);
            $run = ApArSubledgerGlReconciliationRun::withoutGlobalScope('company')->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'ledger' => $ledger, 'as_of_date' => $cutoff,
                'status' => $status, 'algorithm_version' => $algorithmVersion, 'contract_hash' => $contractHash,
                'source_completeness' => $completeness, 'divergence_count' => count($missing), 'snapshot' => $snapshot,
                'snapshot_hash' => $this->hash($snapshot), 'input_cutoff_at' => $canSnapshot ? $inputCutoffAt : null,
                'input_boundary' => $boundary, 'requested_by' => $actor->id, 'recorded_at' => now(),
            ]);
            foreach ($missing as $condition) {
                ApArSubledgerGlReconciliationException::withoutGlobalScope('company')->create([
                    'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'reconciliation_run_id' => $run->id,
                    'exception_code' => $condition['code'], 'severity' => 'blocking', 'reason' => $condition['reason'],
                    'evidence' => ['ledger' => $ledger, 'as_of_date' => $cutoff, 'contract_hash' => $contractHash, 'required_tables' => $condition['required_tables'], ...($condition['evidence'] ?? [])], 'recorded_at' => now(),
                ]);
            }
            app(AuditService::class)->record($run, 'apar_subledger_gl_reconciliation.captured', [], [
                'ledger' => $ledger, 'as_of_date' => $cutoff, 'status' => $status, 'blocking_conditions' => array_column($missing, 'code'),
            ], metadata: ['algorithm_version' => $algorithmVersion, 'side_effects' => 'none', 'amounts_calculated' => (bool) ($snapshot['amounts_calculated'] ?? false), 'tie_out_calculated' => (bool) ($snapshot['tie_out_calculated'] ?? false), 'close_authority' => (bool) ($snapshot['close_authority'] ?? false), 'input_boundary_hash' => $boundary['boundary_hash'] ?? null]);
            return $run->load('exceptions');
        }, 3);
    }

    /** @return array{snapshot:array<string,mixed>,exceptions:list<array<string,mixed>>} */
    private function calculateControlled(int $companyId, string $ledger, string $cutoff, CarbonImmutable $inputCutoffAt, array $contract, ?AccountingPolicyVersion $policy): array
    {
        $invoiceTable = $ledger === 'ap' ? 'purchase_invoices' : 'sales_invoices';
        $invoiceType = $ledger === 'ap' ? 'purchase_invoice' : 'sales_invoice';
        $partyType = $ledger === 'ap' ? 'supplier' : 'customer';
        $ledgerContract = $contract['ledgers'][$ledger];
        $functionalCurrency = strtoupper((string) $contract['functional_currency']);
        $exceptions = [];
        $partyRollforward = [];
        $invoices = DB::table($invoiceTable)
            ->where('company_id', $companyId)->where('is_posted', true)
            ->whereNotNull('accounting_date')->whereDate('accounting_date', '<=', $cutoff)
            ->where('updated_at', '<=', $inputCutoffAt)->orderBy('id')->get();

        $subledger = DecimalMoney::ZERO;
        $invoiceIds = [];
        foreach ($invoices as $invoice) {
            $invoiceIds[] = (int) $invoice->id;
            $amount = $this->exactAmount($invoice->functional_total_amount_raw ?? null, $invoice->functional_total_amount_scale ?? null);
            if ($amount === null || strtoupper((string) ($invoice->functional_currency_code ?? '')) !== $functionalCurrency) {
                $exceptions[] = $this->exception('apar_invoice_functional_amount_evidence_missing', 'Posted invoice is missing exact functional-currency amount evidence.', [$invoiceTable], ['invoice_id' => (int) $invoice->id]);
                continue;
            }
            $partyId = (int) ($invoice->{$partyType.'_id'} ?? 0);
            if ($partyId < 1) {
                $exceptions[] = $this->exception('apar_invoice_party_lineage_missing', 'Posted invoice is missing its tenant-scoped customer/supplier lineage.', [$invoiceTable, $partyType === 'supplier' ? 'suppliers' : 'customers'], ['invoice_id' => (int) $invoice->id]);
            } else {
                $this->addPartyRollforward($partyRollforward, $partyType, $partyId, 'invoice_balance', $amount);
            }
            if (strtoupper((string) ($invoice->currency ?? '')) !== $functionalCurrency) {
                $exceptions[] = $this->exception('apar_foreign_currency_roll_forward_not_integrated', 'Controlled AP/AR reconciliation currently requires local-currency invoices; foreign-currency FX roll-forward is not part of this approved reducer.', [$invoiceTable, 'ap_ar_fx_revaluations'], ['invoice_id' => (int) $invoice->id, 'currency' => (string) $invoice->currency]);
            }
            if ($invoice->journal_entry_id === null) {
                $exceptions[] = $this->exception('apar_invoice_journal_lineage_missing', 'Posted invoice has no linked journal entry.', ['journal_entries', 'journal_entry_lines'], ['invoice_id' => (int) $invoice->id]);
            } else {
                $linked = DB::table('journal_entries')->where('id', $invoice->journal_entry_id)->where('company_id', $companyId)
                    ->where('status', 'posted')->whereDate('posting_date', '<=', $cutoff)->where('updated_at', '<=', $inputCutoffAt)->exists();
                if (! $linked) {
                    $exceptions[] = $this->exception('apar_invoice_journal_lineage_invalid', 'Posted invoice journal lineage is missing, cross-tenant, unposted or after the cutoff.', ['journal_entries'], ['invoice_id' => (int) $invoice->id, 'journal_entry_id' => (int) $invoice->journal_entry_id]);
                }
            }
            $subledger = DecimalMoney::add($subledger, $amount);
        }

        $allocationRows = DB::table('settlement_allocations')
            ->where('company_id', $companyId)->where('status', 'posted')->whereDate('effective_date', '<=', $cutoff)
            ->where('target_document_type', $invoiceType)
            ->where('updated_at', '<=', $inputCutoffAt)->orderBy('id')->get();
        $settlementCount = 0;
        foreach ($allocationRows as $allocation) {
            if (! in_array((string) $allocation->source_document_type, $ledgerContract['settlement_source_types'], true)) {
                $exceptions[] = $this->exception('apar_settlement_source_type_not_in_approved_contract', 'Posted settlement source type is not covered by the approved AP/AR reducer contract.', ['settlement_allocations'], ['allocation_id' => (int) $allocation->id, 'source_document_type' => (string) $allocation->source_document_type]);
                continue;
            }
            $targetId = (int) $allocation->target_document_id;
            if (! in_array($targetId, $invoiceIds, true)) {
                $exceptions[] = $this->exception('apar_settlement_target_not_in_cutoff_open_items', 'Posted settlement references an invoice outside this tenant and cutoff.', ['settlement_allocations', $invoiceTable], ['allocation_id' => (int) $allocation->id, 'target_document_id' => $targetId]);
                continue;
            }
            if (in_array((string) $allocation->source_document_type, $ledgerContract['line_required_source_types'], true)
                && ($allocation->source_line_type === null || $allocation->source_line_id === null)) {
                $exceptions[] = $this->exception('apar_settlement_source_lineage_missing', 'Payment settlement is missing its typed source line.', ['settlement_allocations'], ['allocation_id' => (int) $allocation->id]);
                continue;
            }
            $amount = $this->exactAmount($allocation->functional_amount_raw ?? null, $allocation->functional_amount_scale ?? null);
            if ($amount === null || strtoupper((string) ($allocation->functional_currency_code ?? '')) !== $functionalCurrency) {
                $exceptions[] = $this->exception('apar_settlement_functional_amount_evidence_missing', 'Posted settlement is missing exact functional-currency amount evidence.', ['settlement_allocations'], ['allocation_id' => (int) $allocation->id]);
                continue;
            }
            if (strtoupper((string) ($allocation->currency_code ?? '')) !== $functionalCurrency) {
                $exceptions[] = $this->exception('apar_foreign_currency_roll_forward_not_integrated', 'Controlled AP/AR reconciliation currently requires local-currency settlements; foreign-currency FX roll-forward is not part of this approved reducer.', ['settlement_allocations', 'ap_ar_fx_revaluations'], ['allocation_id' => (int) $allocation->id, 'currency' => (string) $allocation->currency_code]);
            }
            $direction = ($allocation->allocation_direction ?? 'reduction') === 'reversal' ? 'settlement_reversal' : 'settlement_reduction';
            $targetPartyId = (int) ($invoices->firstWhere('id', $targetId)?->{$partyType.'_id'} ?? 0);
            if ($targetPartyId < 1) {
                $exceptions[] = $this->exception('apar_settlement_party_lineage_missing', 'Posted settlement target invoice has no customer/supplier lineage for party roll-forward.', [$invoiceTable, 'settlement_allocations'], ['allocation_id' => (int) $allocation->id, 'target_document_id' => $targetId]);
            } else {
                $this->addPartyRollforward($partyRollforward, $partyType, $targetPartyId, $direction, $amount);
            }
            $subledger = $direction === 'settlement_reversal'
                ? DecimalMoney::add($subledger, $amount)
                : DecimalMoney::subtract($subledger, $amount);
            $settlementCount++;
        }

        $gl = DecimalMoney::ZERO;
        $glLineCount = 0;
        $glRows = DB::table('journal_entry_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('entries.company_id', $companyId)->where('entries.status', 'posted')->whereDate('entries.posting_date', '<=', $cutoff)
            ->where('entries.updated_at', '<=', $inputCutoffAt)->where('lines.updated_at', '<=', $inputCutoffAt)
            ->whereIn('lines.account_code', $ledgerContract['control_account_codes'])
            ->select(['lines.account_code', 'lines.debit_amount', 'lines.credit_amount', 'entries.id as journal_entry_id'])->orderBy('lines.id')->get();
        foreach ($glRows as $line) {
            $debit = $this->decimalAmount($line->debit_amount);
            $credit = $this->decimalAmount($line->credit_amount);
            $gl = $ledgerContract['normal_balance'] === 'credit'
                ? DecimalMoney::add($gl, DecimalMoney::subtract($credit, $debit))
                : DecimalMoney::add($gl, DecimalMoney::subtract($debit, $credit));
            $glLineCount++;
        }

        $opening = $this->openingAmounts($companyId, $ledger, $cutoff, $inputCutoffAt, $ledgerContract);
        $subledger = DecimalMoney::add($subledger, $opening['subledger']);
        $gl = DecimalMoney::add($gl, $opening['gl']);
        foreach ($opening['party'] as $openingParty) {
            $this->addPartyRollforward($partyRollforward, $partyType, (int) $openingParty['party_id'], 'opening_balance', $openingParty['opening_balance']);
        }
        foreach ($partyRollforward as &$party) {
            $party['ending_balance'] = DecimalMoney::add(
                DecimalMoney::add($party['opening_balance'], $party['invoice_balance']),
                DecimalMoney::subtract($party['settlement_reversal'], $party['settlement_reduction']),
            );
        }
        unset($party);
        ksort($partyRollforward, SORT_NATURAL);
        $difference = DecimalMoney::subtract($subledger, $gl);
        if (DecimalMoney::compare($difference, DecimalMoney::ZERO) !== 0) {
            $exceptions[] = $this->exception('apar_subledger_gl_tie_out_mismatch', 'AP/AR subledger does not tie to the approved control-account balance at the cutoff.', ['journal_entry_lines', $invoiceTable, 'settlement_allocations'], ['subledger_balance' => $subledger, 'gl_control_balance' => $gl, 'difference' => $difference]);
        }
        $approvedAt = $policy?->approved_at?->toISOString();
        $snapshot = [
            'schema' => self::CONTROLLED_ALGORITHM_VERSION, 'ledger' => $ledger, 'as_of_date' => $cutoff,
            'status' => $exceptions === [] ? 'reconciled' : 'blocked',
            'amounts' => ['subledger_balance' => $subledger, 'gl_control_balance' => $gl, 'difference' => $difference, 'invoice_count' => count($invoiceIds), 'settlement_count' => $settlementCount, 'gl_control_line_count' => $glLineCount, 'opening_balance_subledger' => $opening['subledger'], 'opening_balance_gl' => $opening['gl'], 'party_rollforward' => array_values($partyRollforward)],
            'amounts_calculated' => true, 'tie_out_calculated' => true, 'close_authority' => $exceptions === [],
            'control' => ['mode' => 'controlled', 'policy_id' => $policy?->id, 'policy_contract_hash' => $policy?->contract_hash, 'approved_at' => $approvedAt, 'eligible_for_period_close' => $exceptions === []],
            'statement' => $exceptions === [] ? 'Controlled AP/AR subledger-to-GL tie-out computed from posted invoices, typed settlements, opening balances and posted control-account lines.' : 'Controlled AP/AR reducer found blocking exceptions; no close authority is granted.',
        ];
        return ['snapshot' => $snapshot, 'exceptions' => $exceptions];
    }

    /** @return array{subledger:string,gl:string,party:list<array{party_id:int,opening_balance:string}>} */
    private function openingAmounts(int $companyId, string $ledger, string $cutoff, CarbonImmutable $inputCutoffAt, array $ledgerContract): array
    {
        if (! Schema::hasTable('opening_balance_packages')) return ['subledger' => DecimalMoney::ZERO, 'gl' => DecimalMoney::ZERO, 'party' => []];
        $package = OpeningBalancePackage::withoutGlobalScope('company')->where('company_id', $companyId)->where('status', 'confirmed')->whereDate('effective_date', '<=', $cutoff)->where('updated_at', '<=', $inputCutoffAt)->latest('effective_date')->latest('id')->first();
        if ($package === null) return ['subledger' => DecimalMoney::ZERO, 'gl' => DecimalMoney::ZERO, 'party' => []];
        $partyType = $ledger === 'ap' ? 'supplier' : 'customer';
        $subledger = DecimalMoney::ZERO;
        $party = [];
        foreach (DB::table('opening_balance_party_lines')->where('package_id', $package->id)->where('party_type', $partyType)->whereIn('account_code', $ledgerContract['control_account_codes'])->where('updated_at', '<=', $inputCutoffAt)->get(['party_id', 'debit_amount', 'credit_amount']) as $line) {
            $partyId = (int) $line->party_id;
            $signed = $ledgerContract['normal_balance'] === 'credit'
                ? DecimalMoney::subtract($this->decimalAmount($line->credit_amount), $this->decimalAmount($line->debit_amount))
                : DecimalMoney::subtract($this->decimalAmount($line->debit_amount), $this->decimalAmount($line->credit_amount));
            $subledger = DecimalMoney::add($subledger, $signed);
            if ($partyId > 0) {
                $party[(string) $partyId] = [
                    'party_id' => $partyId,
                    'opening_balance' => DecimalMoney::add($party[(string) $partyId]['opening_balance'] ?? DecimalMoney::ZERO, $signed),
                ];
            }
        }
        $gl = DecimalMoney::ZERO;
        foreach (DB::table('opening_balance_account_lines')->where('package_id', $package->id)->whereIn('account_code', $ledgerContract['control_account_codes'])->where('updated_at', '<=', $inputCutoffAt)->get(['debit_amount', 'credit_amount']) as $line) {
            $gl = $ledgerContract['normal_balance'] === 'credit'
                ? DecimalMoney::add($gl, DecimalMoney::subtract($this->decimalAmount($line->credit_amount), $this->decimalAmount($line->debit_amount)))
                : DecimalMoney::add($gl, DecimalMoney::subtract($this->decimalAmount($line->debit_amount), $this->decimalAmount($line->credit_amount)));
        }
        ksort($party, SORT_NATURAL);
        return ['subledger' => $subledger, 'gl' => $gl, 'party' => array_values($party)];
    }

    /** @return array<string,mixed> */
    private function inputBoundary(int $companyId, string $ledger, string $cutoff, CarbonImmutable $inputCutoffAt): array
    {
        $invoiceTable = $ledger === 'ap' ? 'purchase_invoices' : 'sales_invoices';
        $sourceSpecs = [
            'open_item_candidates' => ['table' => $invoiceTable, 'date_column' => 'accounting_date', 'id_column' => 'id', 'updated_column' => 'updated_at', 'query' => fn () => DB::table($invoiceTable)->where('company_id', $companyId)->where('is_posted', true)->whereDate('accounting_date', '<=', $cutoff)->where('updated_at', '<=', $inputCutoffAt)],
            'typed_settlement_candidates' => ['table' => 'settlement_allocations', 'date_column' => 'effective_date', 'id_column' => 'id', 'updated_column' => 'updated_at', 'query' => fn () => DB::table('settlement_allocations')->where('company_id', $companyId)->where('target_document_type', $ledger === 'ap' ? 'purchase_invoice' : 'sales_invoice')->where('status', 'posted')->whereDate('effective_date', '<=', $cutoff)->where('updated_at', '<=', $inputCutoffAt)],
            'posted_journal_candidates' => ['table' => 'journal_entries', 'date_column' => 'posting_date', 'id_column' => 'id', 'updated_column' => 'updated_at', 'query' => fn () => DB::table('journal_entries')->where('company_id', $companyId)->where('status', 'posted')->whereDate('posting_date', '<=', $cutoff)->where('updated_at', '<=', $inputCutoffAt)],
            'posted_journal_line_candidates' => ['table' => 'journal_entry_lines', 'date_column' => 'journal_entries.posting_date', 'id_column' => 'lines.id', 'updated_column' => 'lines.updated_at', 'query' => fn () => DB::table('journal_entry_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')->where('entries.company_id', $companyId)->where('entries.status', 'posted')->whereDate('entries.posting_date', '<=', $cutoff)->where('lines.updated_at', '<=', $inputCutoffAt)->where('entries.updated_at', '<=', $inputCutoffAt)],
        ];
        $sources = [];
        foreach ($sourceSpecs as $key => $spec) {
            // These are source-boundary candidates only.  They deliberately
            // do not decide reducer population, source lineage, or mapping.
            $row = $spec['query']()->selectRaw("COUNT(*) AS source_count, MAX({$spec['id_column']}) AS max_id, MAX({$spec['updated_column']}) AS max_updated_at")->first();
            $facts = ['source_key' => $key, 'table' => $spec['table'], 'candidate_date_column' => $spec['date_column'], 'count' => (int) ($row->source_count ?? 0), 'max_id' => $row->max_id === null ? null : (string) $row->max_id, 'max_updated_at' => $row->max_updated_at === null ? null : CarbonImmutable::parse($row->max_updated_at)->utc()->toISOString()];
            $facts['metadata_hash'] = $this->hash($facts);
            $sources[$key] = $facts;
        }
        $boundary = ['schema' => 'apar-input-boundary.v1', 'state' => 'captured_consistent_snapshot', 'database_driver' => DB::connection()->getDriverName(), 'isolation' => DB::connection()->getDriverName() === 'sqlite' ? 'sqlite_read_transaction_snapshot' : 'repeatable_read', 'company_id' => $companyId, 'ledger' => $ledger, 'as_of_date' => $cutoff, 'input_cutoff_at' => $inputCutoffAt->toISOString(), 'sources' => $sources, 'limitation' => 'High-watermarks identify source metadata only; they are not an open-item membership, amount, control-account mapping, or tie-out.'];
        $boundary['boundary_hash'] = $this->hash($boundary);
        return $boundary;
    }

    /** @return array<string,mixed> */
    private function unavailableInputBoundary(int $companyId, string $ledger, string $cutoff, string $driver): array
    {
        $boundary = ['schema' => 'apar-input-boundary.v1', 'state' => 'not_available', 'database_driver' => $driver, 'isolation' => null, 'company_id' => $companyId, 'ledger' => $ledger, 'as_of_date' => $cutoff, 'input_cutoff_at' => null, 'sources' => [], 'reason_code' => 'apar_input_boundary_snapshot_not_supported', 'reason' => 'No demonstrable DB-consistent source snapshot is available; no watermark was captured.'];
        $boundary['boundary_hash'] = $this->hash($boundary);
        return $boundary;
    }

    private function approvedPolicy(int $companyId, string $cutoff): ?AccountingPolicyVersion
    {
        $policies = AccountingPolicyVersion::withoutGlobalScope('company')
            ->where('company_id', $companyId)->where('policy_key', self::POLICY_KEY)
            ->where('status', 'approved')->whereNotNull('approved_at')
            ->whereDate('effective_from', '<=', $cutoff)->whereDate('effective_to', '>=', $cutoff)
            ->orderByDesc('effective_from')->orderByDesc('id')->get();

        // Two approved policies covering the same cutoff are ambiguous even
        // when their dates happen to overlap. Never guess which contract won.
        return $policies->count() === 1 ? $policies->first() : null;
    }

    /** @return array<string,mixed>|null */
    private function reconciliationContract(?AccountingPolicyVersion $policy, string $ledger): ?array
    {
        $contract = $policy?->posting_rule_contract['subledger_gl_reconciliation'] ?? null;
        if (! is_array($contract)
            || ($contract['schema'] ?? null) !== 'apar-subledger-gl-reconciliation.v1'
            || ($contract['algorithm'] ?? null) !== 'invoice-settlement-v1'
            || ! is_string($contract['functional_currency'] ?? null)
            || ! is_array($contract['ledgers'][$ledger] ?? null)) {
            return null;
        }
        $ledgerContract = $contract['ledgers'][$ledger];
        foreach (['control_account_codes', 'settlement_source_types', 'line_required_source_types'] as $key) {
            if (! is_array($ledgerContract[$key] ?? null) || $ledgerContract[$key] === []) return null;
        }
        $accounts = array_values(array_unique(array_map(static fn ($code): string => trim((string) $code), $ledgerContract['control_account_codes'])));
        $sources = array_values(array_unique(array_map(static fn ($type): string => trim((string) $type), $ledgerContract['settlement_source_types'])));
        $lineSources = array_values(array_unique(array_map(static fn ($type): string => trim((string) $type), $ledgerContract['line_required_source_types'])));
        if (in_array('', $accounts, true) || in_array('', $sources, true) || in_array('', $lineSources, true)
            || ! in_array($ledgerContract['normal_balance'] ?? null, ['debit', 'credit'], true)) return null;
        $contract['functional_currency'] = strtoupper(trim((string) $contract['functional_currency']));
        $contract['ledgers'][$ledger]['control_account_codes'] = $accounts;
        $contract['ledgers'][$ledger]['settlement_source_types'] = $sources;
        $contract['ledgers'][$ledger]['line_required_source_types'] = $lineSources;
        return $contract;
    }

    /** @return array<string,array{available:bool,code:string,reason:string,required_tables:list<string>}> */
    private function sourceCompleteness(string $ledger, bool $hasApprovedContract, bool $mappingAvailable): array
    {
        $invoiceTable = $ledger === 'ap' ? 'purchase_invoices' : 'sales_invoices';
        $invoiceType = $ledger === 'ap' ? 'purchase_invoice' : 'sales_invoice';
        $invoiceFields = ['company_id', 'id', 'is_posted', 'currency', 'accounting_date', 'updated_at'];
        $settlementFields = ['company_id', 'id', 'target_document_type', 'target_document_id', 'allocation_kind', 'allocation_direction', 'amount_raw', 'amount_scale', 'effective_date', 'status', 'updated_at'];
        $glEntryFields = ['company_id', 'id', 'status', 'posting_date', 'source_document_type', 'source_document_id', 'updated_at'];
        $glLineFields = ['id', 'journal_entry_id', 'account_code', 'debit_amount', 'credit_amount', 'updated_at'];
        return [
            'open_item_source' => $this->condition(Schema::hasTable($invoiceTable) && Schema::hasColumns($invoiceTable, $invoiceFields), 'open_item_source_schema_unavailable', "The {$invoiceType} open-item source lacks required tenant/posting/currency fields.", [$invoiceTable]),
            'settlement_source' => $this->condition(Schema::hasTable('settlement_allocations') && Schema::hasColumns('settlement_allocations', $settlementFields), 'canonical_settlement_source_unavailable', 'The immutable typed settlement allocation source is unavailable or incomplete.', ['settlement_allocations']),
            'gl_source_lineage' => $this->condition(Schema::hasTable('journal_entries') && Schema::hasColumns('journal_entries', $glEntryFields) && Schema::hasTable('journal_entry_lines') && Schema::hasColumns('journal_entry_lines', $glLineFields), 'gl_source_lineage_schema_unavailable', 'The posted GL source lineage schema is unavailable.', ['journal_entries', 'journal_entry_lines']),
            'owner_approved_reducer' => $this->condition($hasApprovedContract && Schema::hasColumns($invoiceTable, ['functional_currency_code', 'functional_total_amount_raw', 'functional_total_amount_scale', 'original_total_amount_raw', 'original_total_amount_scale']) && Schema::hasColumns('settlement_allocations', ['functional_currency_code', 'functional_amount_raw', 'functional_amount_scale', 'original_currency_code', 'original_amount_raw', 'original_amount_scale']), 'apar_open_item_reducer_not_owner_approved', $hasApprovedContract ? 'The approved AP/AR reducer cannot run because exact functional/original currency evidence is incomplete.' : 'No owner-approved, complete AP/AR reducer proves invoice, settlement, adjustment, reversal and FX treatment at one cutoff.', [$invoiceTable, 'settlement_allocations', 'ap_ar_fx_revaluations']),
            'control_account_mapping' => $this->condition($mappingAvailable, 'apar_control_account_mapping_not_owner_approved', $hasApprovedContract ? 'The approved effective-dated control-account mapping is missing an active tenant account.' : 'No effective-dated owner-approved mapping proves which 131/331 control-account lines belong to this AP/AR subledger.', ['journal_entry_lines', 'accounting_policy_versions', 'chart_of_accounts']),
        ];
    }

    private function exactAmount(mixed $raw, mixed $scale): ?string
    {
        if ($raw === null || $scale === null || ! is_numeric($scale)) return null;
        $scale = (int) $scale;
        if ($scale === 0) {
            $text = trim((string) $raw);
            if (! preg_match('/^[+-]?\d+$/', $text)) return null;
            return DecimalMoney::normalize($text.'.00');
        }
        if ($scale === 2) {
            try { return DecimalMoney::normalize((string) $raw); } catch (\Throwable) { return null; }
        }
        return null;
    }

    private function decimalAmount(mixed $value): string
    {
        try { return DecimalMoney::normalize((string) ($value ?? '0')); } catch (\Throwable) { return DecimalMoney::ZERO; }
    }

    /** @param array<string,array{party_type:string,party_id:int,opening_balance:string,invoice_balance:string,settlement_reduction:string,settlement_reversal:string,ending_balance?:string}> $rollforward */
    private function addPartyRollforward(array &$rollforward, string $partyType, int $partyId, string $field, string $amount): void
    {
        if ($partyId < 1 || ! in_array($field, ['opening_balance', 'invoice_balance', 'settlement_reduction', 'settlement_reversal'], true)) return;
        $key = $partyType.':'.$partyId;
        if (! isset($rollforward[$key])) {
            $rollforward[$key] = [
                'party_type' => $partyType,
                'party_id' => $partyId,
                'opening_balance' => DecimalMoney::ZERO,
                'invoice_balance' => DecimalMoney::ZERO,
                'settlement_reduction' => DecimalMoney::ZERO,
                'settlement_reversal' => DecimalMoney::ZERO,
            ];
        }
        $rollforward[$key][$field] = DecimalMoney::add($rollforward[$key][$field], $amount);
    }

    /** @return array{code:string,reason:string,required_tables:list<string>,evidence:array<string,mixed>} */
    private function exception(string $code, string $reason, array $requiredTables, array $evidence = []): array
    {
        return ['code' => $code, 'reason' => $reason, 'required_tables' => $requiredTables, 'evidence' => $evidence];
    }

    private function controlMappingAvailable(int $companyId, ?array $contract, string $ledger): bool
    {
        if ($contract === null || ! Schema::hasTable('chart_of_accounts')) return false;
        $ledgerContract = $contract['ledgers'][$ledger] ?? null;
        $codes = is_array($ledgerContract) ? ($ledgerContract['control_account_codes'] ?? []) : [];
        $codes = array_values(array_unique(array_filter(array_map(static fn ($code): string => trim((string) $code), $codes))));
        if ($codes === []) return false;
        return DB::table('chart_of_accounts')->where('company_id', $companyId)->where('is_active', true)->whereIn('code', $codes)->count() === count($codes);
    }

    /** @return array{available:bool,code:string,reason:string,required_tables:list<string>} */
    private function condition(bool $available, string $code, string $reason, array $tables): array { return compact('available', 'code', 'reason') + ['required_tables' => $tables]; }
    private function hash(array $payload): string { $this->sort($payload); return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
    private function sort(array &$value): void { foreach ($value as &$item) if (is_array($item)) $this->sort($item); unset($item); if (! array_is_list($value)) ksort($value); }
}
