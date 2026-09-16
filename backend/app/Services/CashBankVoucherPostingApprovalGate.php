<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Approval boundary for the four canonical cash/bank voucher headers only.
 *
 * Contract: an approval request uses the key and subject pair in CONTRACTS,
 * subject_id is the persisted voucher id, and request_evidence contains a
 * non-empty reference_document_type/reference_document_id plus the exact
 * cash_bank_voucher_snapshot_hash returned by snapshotHash().  There is no
 * client-supplied approval id at posting time.
 */
final class CashBankVoucherPostingApprovalGate
{
    /** @var array<class-string<Model>, array{key:string,subject:string}> */
    private const CONTRACTS = [
        \App\Models\CashPayment::class => ['key' => 'cash_payment.post', 'subject' => 'cash_payment'],
        \App\Models\CashReceipt::class => ['key' => 'cash_receipt.post', 'subject' => 'cash_receipt'],
        \App\Models\BankPayment::class => ['key' => 'bank_payment.post', 'subject' => 'bank_payment'],
        \App\Models\BankReceipt::class => ['key' => 'bank_receipt.post', 'subject' => 'bank_receipt'],
    ];

    /** @return array<string,mixed> */
    public function requireApproved(Model $voucher, ?int $postingActorId): array
    {
        $contract = self::contractFor($voucher);
        $requests = ApprovalRequest::withoutGlobalScope('company')->with(['steps.decisions'])
            ->where('company_id', $voucher->getAttribute('company_id'))
            ->where('approval_key', $contract['key'])->where('subject_type', $contract['subject'])
            ->where('subject_id', (string) $voucher->getKey())->where('status', 'approved')
            ->lockForUpdate()->get();
        $valid = $requests->filter(fn (ApprovalRequest $request): bool => $this->isValid($request, $voucher))->values();
        if ($valid->count() !== 1) {
            throw ValidationException::withMessages(['approval' => 'Cash/bank posting requires exactly one completed, current approval request with source-document evidence.']);
        }
        /** @var ApprovalRequest $request */
        $request = $valid->sole();
        if ($request->separation_of_duties_required) {
            if ($postingActorId === null) throw ValidationException::withMessages(['approval' => 'A verified posting actor is required by the cash/bank maker-checker policy.']);
            if ((int) $request->requested_by === $postingActorId) throw ValidationException::withMessages(['approval' => 'The cash/bank approval requester cannot post their own voucher.']);
        }
        return [
            'approval_request_id' => (int) $request->id, 'approval_policy_id' => (int) $request->approval_policy_id,
            'approval_key' => $request->approval_key, 'policy_snapshot' => $request->policy_snapshot,
            'requested_by' => (int) $request->requested_by, 'resolved_by' => (int) $request->resolved_by,
            'resolved_at' => $request->resolved_at?->toAtomString(), 'document_snapshot_hash' => self::snapshotHash($voucher),
            'reference_document_type' => $request->request_evidence['reference_document_type'],
            'reference_document_id' => $request->request_evidence['reference_document_id'],
            'decision_hashes' => $request->steps->flatMap(fn ($step) => $step->decisions)->where('decision', 'approved')->pluck('evidence_hash')->values()->all(),
        ];
    }

    public static function snapshotHash(Model $voucher): string
    {
        if ($voucher->exists) $voucher = $voucher->fresh('lines') ?? $voucher;
        $voucher->loadMissing('lines');
        $header = $voucher->getRawOriginal();
        unset($header['created_at'], $header['updated_at'], $header['deleted_at'], $header['is_posted'], $header['status'], $header['journal_entry_id']);
        $lines = $voucher->lines->sortBy('id')->map(function (Model $line): array {
            $raw = $line->getRawOriginal(); unset($raw['created_at'], $raw['updated_at']); return $raw;
        })->values()->all();
        return hash('sha256', json_encode(['schema' => 'cash-bank-voucher-approval-snapshot/v1', 'voucher_class' => $voucher::class, 'header' => $header, 'lines' => $lines], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{key:string,subject:string} */
    public static function contractFor(Model $voucher): array
    {
        $contract = self::CONTRACTS[$voucher::class] ?? null;
        if ($contract === null) throw ValidationException::withMessages(['approval' => 'This voucher family has no cash/bank approval contract.']);
        return $contract;
    }

    private function isValid(ApprovalRequest $request, Model $voucher): bool
    {
        $evidence = $request->request_evidence;
        if (! is_array($evidence) || ! is_string($evidence['reference_document_type'] ?? null) || trim($evidence['reference_document_type']) === ''
            || filter_var($evidence['reference_document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || ! is_string($evidence['cash_bank_voucher_snapshot_hash'] ?? null) || ! hash_equals(self::snapshotHash($voucher), $evidence['cash_bank_voucher_snapshot_hash'])) return false;
        if (! $request->resolved_at || ! $request->resolved_by || ! $request->requested_by || $request->steps->isEmpty()) return false;
        foreach ($request->steps as $step) {
            $approvals = $step->decisions->where('decision', 'approved');
            if ($step->status !== 'approved' || $approvals->count() < $step->required_approvals) return false;
            if ($request->separation_of_duties_required && $approvals->contains('decided_by', $request->requested_by)) return false;
        }
        return true;
    }
}
