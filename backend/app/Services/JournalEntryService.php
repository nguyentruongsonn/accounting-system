<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Support\SystemPostingSourceRegistry;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class JournalEntryService
{
    use Concerns\GuardsPostedDependentDocuments;

    private const MONEY_SCALE = 2;

    private const MONEY_INTEGER_DIGITS = 16;

    public function __construct(
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly AuditService $auditService,
        private readonly AccountUsageGuard $accountUsageGuard
    ) {}

    public function getAll($perPage = 20, array $filters = [])
    {
        // Do not rely on BelongsToCompany's auth-aware global scope here.  A
        // service can also be called from a queue/command where no HTTP
        // principal exists, so every read must carry an explicit trusted
        // tenant boundary.
        $filters['company_id'] = $this->resolveOperationCompanyId($filters['company_id'] ?? null);
        $query = JournalEntry::with(['lines', 'references'])
            ->orderByDesc('voucher_date')
            ->orderByDesc('id');

        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['voucher_type'])) {
            $query->where('voucher_type', $filters['voucher_type']);
        }

        if (! empty($filters['from_date'])) {
            $query->where('posting_date', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->where('posting_date', '<=', $filters['to_date']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('voucher_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($perPage === -1 || $perPage === 'all') {
            return $query->get();
        }

        return $query->paginate($perPage);
    }

    public function getById($id, ?int $companyId = null)
    {
        $companyId = $this->resolveOperationCompanyId($companyId);

        return JournalEntry::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->with(['lines.bankAccount', 'references', 'referencedBy'])
            ->findOrFail($id);
    }

    public function generateNextCode(int $companyId): string
    {
        $companyId = $this->requireCompanyId($companyId);
        $year = now()->format('Y');
        $prefix = 'PKT-'.$year.'-';

        $latest = JournalEntry::where('company_id', $companyId)
            ->where('voucher_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('voucher_number');

        if ($latest && preg_match('/'.preg_quote($prefix, '/').'(\d+)/', $latest, $m)) {
            $nextSeq = str_pad((int) $m[1] + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $count = JournalEntry::where('company_id', $companyId)->count() + 1;
            $nextSeq = str_pad($count, 4, '0', STR_PAD_LEFT);
        }

        return $prefix.$nextSeq;
    }

    public function create(array $data): JournalEntry
    {
        return $this->persist($data, false);
    }

    /**
     * Persist a journal entry while keeping the public draft boundary separate
     * from trusted domain posting.  A caller that can reach create() must not
     * be able to select status=posted and bypass the explicit post controls.
     * createPosted() is the only service path allowed to request an atomic
     * posted write; its callers still own their source/mapping gates.
     */
    private function persist(array $data, bool $allowPosted): JournalEntry
    {
        return DB::transaction(function () use ($data, $allowPosted) {
            if (empty($data['company_id'])) {
                throw ValidationException::withMessages([
                    'company_id' => 'A trusted company context is required.',
                ]);
            }

            $companyId = $this->requireCompanyId((int) $data['company_id']);
            $postingDate = $data['posting_date'] ?? $data['voucher_date'] ?? now()->toDateString();

            $fiscalYear = $this->resolveFiscalYear($companyId, $data['fiscal_year_id'] ?? null, $postingDate);
            $fiscalYearId = $fiscalYear->id;

            $voucherType = $data['voucher_type'] ?? 'general_journal';
            $voucherNumber = $data['voucher_number'] ?? $this->generateNextCode($companyId);
            $reason = $data['reason'] ?? $data['description'] ?? '';
            $status = $data['status'] ?? 'draft';

            if ($status === 'posted'
                && ! $allowPosted
                && in_array(strtolower((string) config('app.env')), ['production', 'prod'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Không thể tạo bút toán đã ghi sổ từ public draft service; phải đi qua luồng ghi sổ được kiểm soát.',
                ]);
            }

            $postingSource = null;
            if ($status === 'posted' && (array_key_exists('source_document_type', $data) || array_key_exists('source_document_id', $data))) {
                [$postingSource, $canonicalSourceType, $canonicalSourceId] = $this->resolveTrustedPostingSource($data, $companyId);
                if ($postingSource !== null) {
                    $duplicatePostedSource = JournalEntry::withoutGlobalScope('company')
                        ->where('company_id', $companyId)
                        ->where('source_document_type', $canonicalSourceType)
                        ->where('source_document_id', $canonicalSourceId)
                        ->where('status', 'posted')
                        ->lockForUpdate()
                        ->exists();
                    if ($duplicatePostedSource) {
                        throw new ConflictHttpException('Chứng từ nguồn đã có một bút toán đang ghi sổ.');
                    }
                    $data['source_document_type'] = $canonicalSourceType;
                    $data['source_document_id'] = $canonicalSourceId;
                }
            }

            $this->periodGuard->assertOpen($companyId, $postingDate, 'tạo bút toán');

            $totalDebit = '0.00';
            $totalCredit = '0.00';
            $processedLines = [];

            if (! empty($data['lines']) && is_array($data['lines'])) {
                foreach ($data['lines'] as $lineIndex => $line) {
                    $lineDesc = $line['description'] ?? $reason;
                    $contactType = $line['contact_type'] ?? $data['contact_type'] ?? null;
                    $contactId = $line['contact_id'] ?? $data['contact_id'] ?? null;
                    $contactName = $line['contact_name'] ?? $data['contact_name'] ?? null;
                    $costItemCode = $line['cost_item_code'] ?? null;
                    $costObjectCode = $line['cost_object_code'] ?? null;
                    $bankAccountId = $line['bank_account_id'] ?? null;
                    $subObjectType = $line['sub_object_type'] ?? null;
                    $subObjectId = $line['sub_object_id'] ?? null;

                    if (isset($line['debit_account']) && isset($line['credit_account'])) {
                        $amount = $this->normalizeMoney(
                            $line['amount'] ?? $line['debit_amount'] ?? $line['credit_amount'] ?? 0,
                            "lines.{$lineIndex}.amount"
                        );
                        $totalDebit = $this->addMoney($totalDebit, $amount, 'lines');
                        $totalCredit = $this->addMoney($totalCredit, $amount, 'lines');

                        // Debit line
                        $processedLines[] = [
                            'account_code' => $line['debit_account'],
                            'description' => $lineDesc,
                            'debit_amount' => $amount,
                            'credit_amount' => '0.00',
                            'contact_type' => $contactType,
                            'contact_id' => $contactId,
                            'contact_name' => $contactName,
                            'cost_item_code' => $costItemCode,
                            'cost_object_code' => $costObjectCode,
                            'bank_account_id' => $bankAccountId,
                            'sub_object_type' => $subObjectType,
                            'sub_object_id' => $subObjectId,
                        ];

                        // Credit line
                        $processedLines[] = [
                            'account_code' => $line['credit_account'],
                            'description' => $lineDesc,
                            'debit_amount' => '0.00',
                            'credit_amount' => $amount,
                            'contact_type' => $contactType,
                            'contact_id' => $contactId,
                            'contact_name' => $contactName,
                            'cost_item_code' => $costItemCode,
                            'cost_object_code' => $costObjectCode,
                            'bank_account_id' => $bankAccountId,
                            'sub_object_type' => $subObjectType,
                            'sub_object_id' => $subObjectId,
                        ];
                    } else {
                        $d = $this->normalizeMoney($line['debit_amount'] ?? 0, "lines.{$lineIndex}.debit_amount");
                        $c = $this->normalizeMoney($line['credit_amount'] ?? 0, "lines.{$lineIndex}.credit_amount");
                        $totalDebit = $this->addMoney($totalDebit, $d, 'lines');
                        $totalCredit = $this->addMoney($totalCredit, $c, 'lines');

                        $processedLines[] = [
                            'account_code' => $line['account_code'] ?? '',
                            'description' => $lineDesc,
                            'debit_amount' => $d,
                            'credit_amount' => $c,
                            'contact_type' => $contactType,
                            'contact_id' => $contactId,
                            'contact_name' => $contactName,
                            'cost_item_code' => $costItemCode,
                            'cost_object_code' => $costObjectCode,
                            'bank_account_id' => $bankAccountId,
                            'sub_object_type' => $subObjectType,
                            'sub_object_id' => $subObjectId,
                        ];
                    }
                }
            }

            $this->assertValidPostingLines($companyId, $processedLines);

            if ($totalDebit !== $totalCredit) {
                throw new Exception(sprintf(
                    'Double-entry validation failed: Total Debit (%s) does not equal Total Credit (%s).',
                    $this->displayMoney($totalDebit),
                    $this->displayMoney($totalCredit)
                ));
            }

            $existing = JournalEntry::withTrashed()
                ->where('company_id', $companyId)
                ->where('voucher_number', $voucherNumber)
                ->first();
            if ($existing) {
                throw new ConflictHttpException(
                    "Số chứng từ {$voucherNumber} đã tồn tại; không được ghi đè hoặc xóa bút toán cũ."
                );
            }

            $entry = JournalEntry::create([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId,
                'voucher_type' => $voucherType,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $data['voucher_date'] ?? now()->toDateString(),
                'posting_date' => $postingDate,
                'description' => $reason,
                'total_amount' => $totalDebit,
                'status' => $status,
                'currency' => $data['currency'] ?? 'VND',
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'attached_docs' => $data['attached_docs'] ?? null,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'source_document_type' => $data['source_document_type'] ?? null,
                'source_document_id' => $data['source_document_id'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
                'updated_by' => $data['updated_by'] ?? auth()->id(),
            ]);

            foreach ($processedLines as $pLine) {
                $entry->lines()->create($pLine);
            }

            if ($postingSource !== null) {
                $this->linkSourceToLatestJournal($postingSource, $entry);
            }

            if (isset($data['referenced_vouchers'])) {
                $entry->syncReferences($data['referenced_vouchers']);
            }

            $entry->load(['lines', 'references']);
            $this->auditService->record(
                $entry,
                'journal.created',
                [],
                $entry->toArray()
            );

            return $entry;
        });
    }

    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => 'A trusted company context is required.',
            ]);
        }
        if ($actorCompanyId !== null && (int) $actorCompanyId !== $companyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }

    /**
     * Resolve the tenant for public journal operations.  Explicit tenant
     * context is required for non-HTTP callers; an authenticated principal
     * may only operate on its own company.  This deliberately does not infer
     * a company from the journal row being addressed.
     */
    private function resolveOperationCompanyId(?int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        $resolved = $companyId ?? ($actorCompanyId !== null ? (int) $actorCompanyId : null);

        if ($resolved === null || $resolved <= 0) {
            throw ValidationException::withMessages([
                'company_id' => 'A trusted company context is required for this journal operation.',
            ]);
        }

        if ($actorCompanyId !== null && (int) $actorCompanyId !== $resolved) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $resolved;
    }

    /**
     * Trusted domain posting path. HTTP controllers must use create() and then
     * the explicit post transition; this method is reserved for services that
     * atomically post their source document and its GL entry.
     */
    public function createPosted(array $data): JournalEntry
    {
        return DB::transaction(function () use ($data) {
            $companyId = (int) ($data['company_id'] ?? 0);
            if ($companyId <= 0) {
                throw ValidationException::withMessages([
                    'company_id' => 'A trusted company context is required.',
                ]);
            }

            $authenticatedCompanyId = auth()->user()?->company_id;
            if ($authenticatedCompanyId !== null && (int) $authenticatedCompanyId !== $companyId) {
                throw ValidationException::withMessages([
                    'company_id' => 'The posting company must match the authenticated tenant.',
                ]);
            }

            [$source, $sourceType, $sourceId] = $this->resolveTrustedPostingSource($data, $companyId);
            if ($source !== null) {
                // The source row is locked by resolveTrustedPostingSource(). This
                // serializes retries for the same aggregate even on databases that
                // do not support a partial unique index for status = posted.
                $activeEntry = JournalEntry::withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->where('source_document_type', $sourceType)
                    ->where('source_document_id', $sourceId)
                    ->where('status', 'posted')
                    ->lockForUpdate()
                    ->first();

                if ($activeEntry !== null) {
                    $this->linkSourceToLatestJournal($source, $activeEntry);

                    return $activeEntry->load(['lines', 'references']);
                }

                $data['source_document_type'] = $sourceType;
                $data['source_document_id'] = $sourceId;
            }

            $baseVoucherNumber = trim((string) ($data['voucher_number'] ?? ''));
            if ($baseVoucherNumber === '') {
                throw ValidationException::withMessages([
                    'voucher_number' => 'Trusted posting requires a voucher number.',
                ]);
            }

            $existing = JournalEntry::withoutGlobalScope('company')
                ->withTrashed()
                ->where('company_id', $companyId)
                ->where('voucher_number', $baseVoucherNumber)
                ->first();

            if ($existing?->status === 'posted') {
                throw new ConflictHttpException("Số chứng từ {$baseVoucherNumber} đã được ghi sổ.");
            }

            if ($existing !== null) {
                $revision = 1;
                do {
                    $candidate = "{$baseVoucherNumber}-R{$revision}";
                    $revision++;
                } while (JournalEntry::withoutGlobalScope('company')->withTrashed()
                    ->where('company_id', $companyId)
                    ->where('voucher_number', $candidate)
                    ->exists());
                $data['voucher_number'] = $candidate;
            }

            $data['status'] = 'posted';
            $entry = $this->persist($data, true);
            if ($source !== null) {
                $this->linkSourceToLatestJournal($source, $entry);
            }
            $this->auditService->record(
                $entry,
                'journal.posted',
                ['status' => 'draft'],
                $entry->toArray(),
                null,
                ['posting_path' => 'trusted_domain_service']
            );

            return $entry;
        });
    }

    /**
     * @return array{0: Model|null, 1: string|null, 2: int|null}
     */
    private function resolveTrustedPostingSource(array $data, int $companyId): array
    {
        $rawType = $data['source_document_type'] ?? null;
        $rawId = $data['source_document_id'] ?? null;

        if ($rawType === null && $rawId === null) {
            // Some system-generated batches do not yet have a canonical persisted
            // aggregate (period close and monthly costing). Those callers remain
            // explicitly unlinked until their domain headers are introduced.
            return [null, null, null];
        }

        if (! is_string($rawType) || trim($rawType) === '' || filter_var($rawId, FILTER_VALIDATE_INT) === false || (int) $rawId <= 0) {
            throw ValidationException::withMessages([
                'source_document' => 'Trusted posting requires both a valid source model type and source id.',
            ]);
        }

        $sourceClass = trim($rawType);
        if (! class_exists($sourceClass)
            || ! is_subclass_of($sourceClass, Model::class)
            || ! SystemPostingSourceRegistry::supports($sourceClass)) {
            throw ValidationException::withMessages([
                'source_document_type' => 'The posting source type is not registered for trusted accounting posting.',
            ]);
        }

        /** @var Model $prototype */
        $prototype = new $sourceClass;
        /** @var Model|null $source */
        // Constrain the source tenant before taking a row lock. A direct
        // caller may supply a valid foreign source ID; that row must not be
        // hydrated/locked before the posting boundary rejects it.
        $source = $prototype->newQueryWithoutScopes()
            ->whereKey((int) $rawId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        if ($source === null) {
            throw ValidationException::withMessages([
                'source_document_id' => 'The posting source does not exist.',
            ]);
        }

        if (method_exists($source, 'trashed') && $source->trashed()) {
            throw ValidationException::withMessages([
                'source_document_id' => 'A deleted source document cannot be posted.',
            ]);
        }

        $sourceCompanyId = $source->getAttribute('company_id');
        if ($sourceCompanyId === null || (int) $sourceCompanyId !== $companyId) {
            throw ValidationException::withMessages([
                'source_document_id' => 'The posting source must belong to the journal company.',
            ]);
        }

        return [$source, $source->getMorphClass(), (int) $source->getKey()];
    }

    private function linkSourceToLatestJournal(Model $source, JournalEntry $entry): void
    {
        if (! array_key_exists('journal_entry_id', $source->getAttributes())) {
            return;
        }

        $linkedId = $source->getAttribute('journal_entry_id');
        if ($linkedId !== null && (int) $linkedId !== (int) $entry->id) {
            // A source header is tenant-bound evidence.  Do not let a corrupt
            // cross-tenant journal_entry_id be silently overwritten while
            // posting the source; that would destroy the only pointer to the
            // foreign journal and make lineage repair non-auditable.
            $linkedEntry = JournalEntry::withoutGlobalScope('company')
                ->withTrashed()
                ->where('company_id', (int) $source->getAttribute('company_id'))
                ->find($linkedId);
            if ($linkedEntry === null) {
                throw new ConflictHttpException('Chứng từ nguồn đang chứa liên kết bút toán không thuộc doanh nghiệp hoặc không tồn tại.');
            }
            if ($linkedEntry->status === 'posted') {
                throw new ConflictHttpException('Chứng từ nguồn đang liên kết với một bút toán đã ghi sổ khác.');
            }
        }

        if ((int) $linkedId !== (int) $entry->id) {
            $source->setAttribute('journal_entry_id', $entry->id);
            $source->saveQuietly();
        }
    }

    public function update($id, array $data, ?int $companyId = null): JournalEntry
    {
        $companyId = $this->resolveOperationCompanyId($companyId);

        return DB::transaction(function () use ($id, $data, $companyId) {
            $entry = JournalEntry::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($id);

            if ($entry->status === 'posted') {
                throw new ConflictHttpException('Không được sửa bút toán đã ghi sổ. Hãy lập nghiệp vụ điều chỉnh/đảo riêng.');
            }

            // Capture the draft and its child evidence before replacement.  A
            // manual voucher is still accounting evidence before posting, so a
            // material draft edit must be traceable and must roll back if audit
            // persistence is unavailable.
            $before = $entry->load('references')->toArray();

            $reason = $data['reason'] ?? $data['description'] ?? $entry->description;
            $voucherDate = $data['voucher_date'] ?? $entry->voucher_date;
            $postingDate = $data['posting_date'] ?? $entry->posting_date;
            $fiscalYear = $this->resolveFiscalYear(
                (int) $entry->company_id,
                $data['fiscal_year_id'] ?? $entry->fiscal_year_id,
                (string) $postingDate
            );

            $this->periodGuard->assertOpen($entry->company_id, $entry->posting_date, 'sửa bút toán');
            $this->periodGuard->assertOpen($entry->company_id, $postingDate, 'chuyển ngày bút toán');

            $totalDebit = '0.00';
            $totalCredit = '0.00';
            $processedLines = [];

            if (isset($data['lines']) && is_array($data['lines'])) {
                foreach ($data['lines'] as $lineIndex => $line) {
                    $lineDesc = $line['description'] ?? $reason;
                    $contactType = $line['contact_type'] ?? null;
                    $contactId = $line['contact_id'] ?? null;
                    $contactName = $line['contact_name'] ?? null;
                    $costItemCode = $line['cost_item_code'] ?? null;
                    $costObjectCode = $line['cost_object_code'] ?? null;
                    $bankAccountId = $line['bank_account_id'] ?? null;
                    $subObjectType = $line['sub_object_type'] ?? null;
                    $subObjectId = $line['sub_object_id'] ?? null;

                    if (isset($line['debit_account']) && isset($line['credit_account'])) {
                        $amount = $this->normalizeMoney(
                            $line['amount'] ?? $line['debit_amount'] ?? $line['credit_amount'] ?? 0,
                            "lines.{$lineIndex}.amount"
                        );
                        $totalDebit = $this->addMoney($totalDebit, $amount, 'lines');
                        $totalCredit = $this->addMoney($totalCredit, $amount, 'lines');

                        $processedLines[] = [
                            'account_code' => $line['debit_account'],
                            'description' => $lineDesc,
                            'debit_amount' => $amount,
                            'credit_amount' => '0.00',
                            'contact_type' => $contactType,
                            'contact_id' => $contactId,
                            'contact_name' => $contactName,
                            'cost_item_code' => $costItemCode,
                            'cost_object_code' => $costObjectCode,
                            'bank_account_id' => $bankAccountId,
                            'sub_object_type' => $subObjectType,
                            'sub_object_id' => $subObjectId,
                        ];

                        $processedLines[] = [
                            'account_code' => $line['credit_account'],
                            'description' => $lineDesc,
                            'debit_amount' => '0.00',
                            'credit_amount' => $amount,
                            'contact_type' => $contactType,
                            'contact_id' => $contactId,
                            'contact_name' => $contactName,
                            'cost_item_code' => $costItemCode,
                            'cost_object_code' => $costObjectCode,
                            'bank_account_id' => $bankAccountId,
                            'sub_object_type' => $subObjectType,
                            'sub_object_id' => $subObjectId,
                        ];
                    } else {
                        $d = $this->normalizeMoney($line['debit_amount'] ?? 0, "lines.{$lineIndex}.debit_amount");
                        $c = $this->normalizeMoney($line['credit_amount'] ?? 0, "lines.{$lineIndex}.credit_amount");
                        $totalDebit = $this->addMoney($totalDebit, $d, 'lines');
                        $totalCredit = $this->addMoney($totalCredit, $c, 'lines');

                        $processedLines[] = [
                            'account_code' => $line['account_code'] ?? '',
                            'description' => $lineDesc,
                            'debit_amount' => $d,
                            'credit_amount' => $c,
                            'contact_type' => $contactType,
                            'contact_id' => $contactId,
                            'contact_name' => $contactName,
                            'cost_item_code' => $costItemCode,
                            'cost_object_code' => $costObjectCode,
                            'bank_account_id' => $bankAccountId,
                            'sub_object_type' => $subObjectType,
                            'sub_object_id' => $subObjectId,
                        ];
                    }
                }

                $this->assertValidPostingLines((int) $entry->company_id, $processedLines);

                if ($totalDebit !== $totalCredit) {
                    throw new Exception(sprintf(
                        'Double-entry validation failed: Total Debit (%s) does not equal Total Credit (%s).',
                        $this->displayMoney($totalDebit),
                        $this->displayMoney($totalCredit)
                    ));
                }
            } else {
                $this->assertValidPostingLines((int) $entry->company_id, $entry->lines->toArray());
            }

            $entry->update([
                'fiscal_year_id' => $fiscalYear->id,
                'voucher_type' => $data['voucher_type'] ?? $entry->voucher_type,
                'voucher_number' => $data['voucher_number'] ?? $entry->voucher_number,
                'voucher_date' => $voucherDate,
                'posting_date' => $postingDate,
                'description' => $reason,
                'total_amount' => ! empty($processedLines) ? $totalDebit : $entry->total_amount,
                'currency' => $data['currency'] ?? $entry->currency,
                'exchange_rate' => $data['exchange_rate'] ?? $entry->exchange_rate,
                'attached_docs' => $data['attached_docs'] ?? $entry->attached_docs,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? $entry->referenced_vouchers,
                'updated_by' => auth()->id(),
            ]);

            if (! empty($processedLines)) {
                $entry->lines()->delete();
                foreach ($processedLines as $pLine) {
                    $entry->lines()->create($pLine);
                }
            }

            if (isset($data['referenced_vouchers'])) {
                $entry->syncReferences($data['referenced_vouchers']);
            }

            $entry->load(['lines', 'references']);
            $this->auditService->record($entry, 'journal.updated', $before, $entry->toArray());

            return $entry;
        });
    }

    public function post($id, ?int $companyId = null): JournalEntry
    {
        $companyId = $this->resolveOperationCompanyId($companyId);

        return DB::transaction(function () use ($id, $companyId) {
            $entry = JournalEntry::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($id);
            if ($entry->status === 'posted') {
                throw new ConflictHttpException('Bút toán đã được ghi sổ.');
            }
            if ($entry->status !== 'draft') {
                throw new ConflictHttpException('Chỉ được ghi sổ bút toán đang ở trạng thái nháp.');
            }

            if ($entry->source_document_type !== null || $entry->source_document_id !== null) {
                throw new ConflictHttpException(
                    'Bút toán nháp có liên kết chứng từ nguồn không được ghi sổ thủ công; hãy ghi sổ từ nghiệp vụ nguồn.'
                );
            }

            // A valid leaf account proves only structural validity. Until a
            // Chief-Accountant-approved manual-journal policy/mapping resolver
            // is integrated, production must not turn arbitrary account input
            // into a posted ledger entry.
            if (in_array(strtolower((string) config('app.env')), ['production', 'prod'], true)) {
                throw ValidationException::withMessages([
                    'account_mappings' => 'Không thể ghi sổ bút toán thủ công trong production khi chưa có chính sách và mapping tài khoản được phê duyệt.',
                ]);
            }

            $this->resolveFiscalYear(
                (int) $entry->company_id,
                $entry->fiscal_year_id,
                (string) $entry->posting_date
            );
            $this->assertValidPostingLines((int) $entry->company_id, $entry->lines->toArray());
            $this->periodGuard->assertOpen($entry->company_id, $entry->posting_date, 'ghi sổ bút toán');
            $before = $entry->toArray();
            $entry->status = 'posted';
            $entry->updated_by = auth()->id();
            $entry->save();
            $entry->load(['lines', 'references']);
            $this->auditService->record($entry, 'journal.posted', $before, $entry->toArray());

            return $entry;
        });
    }

    public function reverse(int $id, int $companyId, string $reason, ?string $postingDate = null): JournalEntry
    {
        // Keep the service boundary tenant-safe even when an internal caller
        // bypasses the HTTP controller/TenantContext middleware.  The query
        // below is intentionally scoped by company, but accepting a caller-
        // selected foreign company here would still allow a privileged
        // application path to mutate another tenant's posted history.
        $companyId = $this->requireCompanyId($companyId);

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Phải nêu lý do đảo bút toán.',
            ]);
        }

        return DB::transaction(function () use ($id, $companyId, $reason, $postingDate) {
            $original = JournalEntry::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($id);

            if ($original->status !== 'posted') {
                throw new ConflictHttpException('Chỉ được đảo bút toán đã ghi sổ.');
            }
            if ($original->reversal_of_id !== null) {
                throw new ConflictHttpException('Không được đảo lại một bút toán đảo.');
            }
            if ($original->reversed_by_entry_id !== null) {
                throw new ConflictHttpException('Bút toán này đã có chứng từ đảo.');
            }

            $reversalDate = $postingDate ?? now()->toDateString();
            $this->periodGuard->assertOpen($companyId, $reversalDate, 'đảo bút toán');

            $fiscalYear = FiscalYear::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->whereDate('start_date', '<=', $reversalDate)
                ->whereDate('end_date', '>=', $reversalDate)
                ->first();
            if ($fiscalYear === null) {
                throw ValidationException::withMessages([
                    'posting_date' => 'Ngày đảo bút toán không thuộc năm tài chính của doanh nghiệp.',
                ]);
            }

            $correlationId = (string) Str::uuid();
            $actorId = auth()->id();
            $reversedAt = now();
            $originalBefore = $original->toArray();
            // A reversal is an explicit controlled posting transition, not a
            // public draft create.  Use the trusted persist path so production
            // reversals remain available while direct status=posted creates
            // stay fail-closed.
            $reversal = $this->persist([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYear->id,
                'voucher_type' => 'journal_reversal',
                'voucher_number' => sprintf('REV-%d-%s-%s', $original->id, $reversedAt->format('YmdHis'), Str::upper(Str::random(6))),
                'voucher_date' => $reversalDate,
                'posting_date' => $reversalDate,
                'description' => "Đảo {$original->voucher_number}: {$reason}",
                'status' => 'posted',
                'source_document_type' => JournalEntry::class,
                'source_document_id' => $original->id,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'lines' => $original->lines->map(static fn ($line) => [
                    'account_code' => $line->account_code,
                    'description' => $line->description,
                    'debit_amount' => (string) $line->credit_amount,
                    'credit_amount' => (string) $line->debit_amount,
                    'contact_type' => $line->contact_type,
                    'contact_id' => $line->contact_id,
                    'contact_name' => $line->contact_name,
                    'cost_item_code' => $line->cost_item_code,
                    'cost_object_code' => $line->cost_object_code,
                    'bank_account_id' => $line->bank_account_id,
                    'sub_object_type' => $line->sub_object_type,
                    'sub_object_id' => $line->sub_object_id,
                ])->all(),
            ], true);

            $reversal->update([
                'reversal_of_id' => $original->id,
                'reversal_reason' => $reason,
                'reversed_by' => $actorId,
                'reversed_at' => $reversedAt,
            ]);
            $original->update([
                'reversed_by_entry_id' => $reversal->id,
                'reversal_reason' => $reason,
                'reversed_by' => $actorId,
                'reversed_at' => $reversedAt,
            ]);

            $reversal->load(['lines', 'references']);
            $this->auditService->record(
                $original,
                'journal.reversed',
                $originalBefore,
                $original->fresh()->toArray(),
                $correlationId,
                ['reversal_entry_id' => $reversal->id, 'reason' => $reason]
            );

            return $reversal;
        });
    }

    /**
     * Operational cancellation in an open period. This changes workflow state
     * only; it is not a substitute for reverse(), which creates opposite lines
     * when accounting history must remain posted.
     */
    public function void($id, ?int $companyId = null): JournalEntry
    {
        return $this->transitionPostedEntryToVoided($id, $companyId, 'journal.voided');
    }

    /**
     * MISA-like “bỏ ghi sổ” for correcting an operational source document in
     * an open period. Closed-period/history corrections must use reverse().
     */
    public function unpost($id, ?int $companyId = null): JournalEntry
    {
        return $this->transitionPostedEntryToVoided($id, $companyId, 'journal.unposted');
    }

    private function transitionPostedEntryToVoided(int|string $id, ?int $companyId, string $action): JournalEntry
    {
        $tenantId = $this->resolveOperationCompanyId($companyId);

        return DB::transaction(function () use ($id, $tenantId, $action) {

            $entry = JournalEntry::withoutGlobalScope('company')
                ->where('company_id', (int) $tenantId)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($entry->status === 'voided') {
                return $entry->load(['lines', 'references']);
            }
            if ($entry->status !== 'posted') {
                throw new ConflictHttpException('Chỉ được bỏ ghi sổ hoặc hủy bút toán đang ở trạng thái đã ghi sổ.');
            }
            if ($entry->reversal_of_id !== null || $entry->reversed_by_entry_id !== null) {
                throw new ConflictHttpException('Bút toán đã tham gia chuỗi đảo; không được bỏ ghi sổ hoặc hủy trực tiếp.');
            }

            $operation = $action === 'journal.unposted' ? 'bỏ ghi sổ bút toán' : 'hủy bút toán';
            $this->assertNoPostedDependentDocuments((int) $tenantId, JournalEntry::class, (int) $entry->id);
            $this->periodGuard->assertOpen((int) $tenantId, $entry->posting_date, $operation);

            $before = $entry->toArray();
            $entry->status = 'voided';
            $entry->updated_by = auth()->id();
            $entry->save();
            $entry->load(['lines', 'references']);
            $this->auditService->record(
                $entry,
                $action,
                $before,
                $entry->toArray(),
                null,
                ['semantic' => $action === 'journal.unposted' ? 'open_period_operational_correction' : 'open_period_void']
            );

            return $entry;
        });
    }

    public function duplicate($id, ?int $companyId = null): JournalEntry
    {
        $companyId = $this->resolveOperationCompanyId($companyId);

        return DB::transaction(function () use ($id, $companyId) {
            $original = JournalEntry::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($id);
            $newVoucherNumber = $this->generateNextCode($original->company_id);
            $postingDate = now()->toDateString();
            $fiscalYear = $this->resolveFiscalYear((int) $original->company_id, null, $postingDate);
            $this->assertValidPostingLines((int) $original->company_id, $original->lines->toArray());

            $this->periodGuard->assertOpen($original->company_id, $postingDate, 'nhân bản bút toán');

            $newEntry = $original->replicate();
            $newEntry->voucher_number = $newVoucherNumber;
            $newEntry->fiscal_year_id = $fiscalYear->id;
            $newEntry->voucher_date = $postingDate;
            $newEntry->posting_date = $postingDate;
            $newEntry->status = 'draft';
            // A duplicate is a new manual voucher, never another posting of
            // the original source aggregate or a continuation of its reversal
            // chain. Keeping these links would bypass the one-active-JE/source
            // invariant when the duplicate is posted later.
            $newEntry->source_document_type = null;
            $newEntry->source_document_id = null;
            $newEntry->reversal_of_id = null;
            $newEntry->reversed_by_entry_id = null;
            $newEntry->reversal_reason = null;
            $newEntry->reversed_by = null;
            $newEntry->reversed_at = null;
            $newEntry->save();

            foreach ($original->lines as $line) {
                $newLine = $line->replicate();
                $newLine->journal_entry_id = $newEntry->id;
                $newLine->save();
            }

            if ($original->referenced_vouchers) {
                $newEntry->syncReferences($original->referenced_vouchers);
            }

            $newEntry->load(['lines', 'references']);
            $this->auditService->record(
                $newEntry,
                'journal.duplicated',
                [],
                $newEntry->toArray(),
                null,
                ['duplicated_from_journal_entry_id' => $original->id]
            );

            return $newEntry;
        });
    }

    public function delete($id, ?int $companyId = null): void
    {
        $companyId = $this->resolveOperationCompanyId($companyId);

        DB::transaction(function () use ($id, $companyId) {
            $entry = JournalEntry::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($id);
            if ($entry->status === 'posted') {
                throw new ConflictHttpException('Không được xóa bút toán đã ghi sổ. Hãy lập nghiệp vụ điều chỉnh/đảo riêng.');
            }

            $this->periodGuard->assertOpen($entry->company_id, $entry->posting_date, 'xóa bút toán');
            $before = $entry->load(['lines', 'references'])->toArray();
            $entry->lines()->delete();
            $entry->references()->delete();
            $entry->delete();
            $this->auditService->record($entry, 'journal.deleted', $before, $entry->toArray());
        });
    }

    private function resolveFiscalYear(int $companyId, mixed $fiscalYearId, string $postingDate): FiscalYear
    {
        try {
            $postingDate = Carbon::parse($postingDate)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'posting_date' => 'Ngày ghi sổ không hợp lệ.',
            ]);
        }

        $query = FiscalYear::withoutGlobalScope('company')
            ->where('company_id', $companyId);

        if ($fiscalYearId !== null && $fiscalYearId !== '') {
            $fiscalYear = (clone $query)->find($fiscalYearId);
            if ($fiscalYear === null) {
                throw ValidationException::withMessages([
                    'fiscal_year_id' => 'Năm tài chính phải thuộc doanh nghiệp hiện tại.',
                ]);
            }

            if ($postingDate < $fiscalYear->start_date->toDateString()
                || $postingDate > $fiscalYear->end_date->toDateString()) {
                throw ValidationException::withMessages([
                    'posting_date' => 'Ngày ghi sổ phải nằm trong năm tài chính đã chọn.',
                ]);
            }

            return $fiscalYear;
        }

        $fiscalYear = $query
            ->whereDate('start_date', '<=', $postingDate)
            ->whereDate('end_date', '>=', $postingDate)
            ->orderByDesc('id')
            ->first();

        if ($fiscalYear === null) {
            throw ValidationException::withMessages([
                'posting_date' => 'Ngày ghi sổ không thuộc năm tài chính nào của doanh nghiệp.',
            ]);
        }

        return $fiscalYear;
    }

    /**
     * Enforce the same account and non-zero line invariants for public drafts,
     * trusted source-module postings, period closing and later post transitions.
     * Monetary arithmetic is performed with canonical fixed-scale decimal strings.
     */
    private function assertValidPostingLines(int $companyId, array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Bút toán phải có ít nhất một dòng định khoản.',
            ]);
        }

        $accountCodes = [];
        $totalDebit = '0.00';
        $totalCredit = '0.00';
        foreach ($lines as $index => $line) {
            $accountCode = trim((string) ($line['account_code'] ?? ''));
            $debit = $this->normalizeMoney($line['debit_amount'] ?? 0, "lines.{$index}.debit_amount");
            $credit = $this->normalizeMoney($line['credit_amount'] ?? 0, "lines.{$index}.credit_amount");

            if ($accountCode === '') {
                throw ValidationException::withMessages([
                    "lines.{$index}.account_code" => 'Mỗi dòng định khoản phải có tài khoản.',
                ]);
            }

            if (($debit === '0.00' && $credit === '0.00') || ($debit !== '0.00' && $credit !== '0.00')) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => 'Mỗi dòng phải có đúng một bên Nợ hoặc Có lớn hơn 0.',
                ]);
            }

            $totalDebit = $this->addMoney($totalDebit, $debit, 'lines');
            $totalCredit = $this->addMoney($totalCredit, $credit, 'lines');
            $accountCodes[] = $accountCode;
        }

        if ($totalDebit !== $totalCredit) {
            throw new Exception(sprintf(
                'Double-entry validation failed: Total Debit (%s) does not equal Total Credit (%s).',
                $this->displayMoney($totalDebit),
                $this->displayMoney($totalCredit)
            ));
        }

        $this->accountUsageGuard->lockActiveLeafAccounts($companyId, $accountCodes);
    }

    /**
     * Convert an API monetary value to the exact DECIMAL(18,2) representation.
     * Excess fractional digits and integer overflow are rejected rather than rounded.
     */
    private function normalizeMoney(mixed $value, string $field): string
    {
        if (is_int($value)) {
            $raw = (string) $value;
        } elseif (is_float($value)) {
            if (! is_finite($value)) {
                throw ValidationException::withMessages([$field => 'Số tiền phải là số hữu hạn.']);
            }
            $raw = (string) $value;
        } elseif (is_string($value)) {
            $raw = trim($value);
        } else {
            throw ValidationException::withMessages([$field => 'Số tiền không hợp lệ.']);
        }

        $raw = $this->expandScientificNotation($raw);
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $raw, $matches)) {
            throw ValidationException::withMessages([$field => 'Số tiền không hợp lệ.']);
        }
        if ($matches[1] === '-') {
            throw ValidationException::withMessages([$field => 'Số tiền không được âm.']);
        }

        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $matches[3] ?? '';
        if (strlen($fraction) > self::MONEY_SCALE) {
            throw ValidationException::withMessages([
                $field => 'Số tiền chỉ được có tối đa 2 chữ số thập phân; hệ thống không tự làm tròn.',
            ]);
        }
        if (strlen($integer) > self::MONEY_INTEGER_DIGITS) {
            throw ValidationException::withMessages([
                $field => 'Số tiền vượt quá giới hạn DECIMAL(18,2).',
            ]);
        }

        return $integer.'.'.str_pad($fraction, self::MONEY_SCALE, '0');
    }

    private function addMoney(string $left, string $right, string $field): string
    {
        $leftMinor = str_replace('.', '', $left);
        $rightMinor = str_replace('.', '', $right);
        $sumMinor = $this->addUnsignedIntegers($leftMinor, $rightMinor);

        if (strlen($sumMinor) > self::MONEY_INTEGER_DIGITS + self::MONEY_SCALE) {
            throw ValidationException::withMessages([
                $field => 'Tổng số tiền vượt quá giới hạn DECIMAL(18,2).',
            ]);
        }

        $sumMinor = str_pad($sumMinor, self::MONEY_SCALE + 1, '0', STR_PAD_LEFT);
        $integer = substr($sumMinor, 0, -self::MONEY_SCALE);
        $fraction = substr($sumMinor, -self::MONEY_SCALE);

        return $integer.'.'.$fraction;
    }

    private function addUnsignedIntegers(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $carry = 0;
        $result = '';

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry;
            if ($leftIndex >= 0) {
                $sum += ord($left[$leftIndex--]) - 48;
            }
            if ($rightIndex >= 0) {
                $sum += ord($right[$rightIndex--]) - 48;
            }
            $result = (string) ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function expandScientificNotation(string $value): string
    {
        if (! str_contains(strtolower($value), 'e')) {
            return $value;
        }
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d*))?[eE]([+-]?\d+)$/', $value, $matches)) {
            return $value;
        }

        $sign = $matches[1];
        $integer = $matches[2];
        $fraction = $matches[3] ?? '';
        $exponent = (int) $matches[4];
        $digits = $integer.$fraction;
        $decimalPosition = strlen($integer) + $exponent;

        if ($decimalPosition <= 0) {
            return $sign.'0.'.str_repeat('0', -$decimalPosition).$digits;
        }
        if ($decimalPosition >= strlen($digits)) {
            return $sign.$digits.str_repeat('0', $decimalPosition - strlen($digits));
        }

        return $sign.substr($digits, 0, $decimalPosition).'.'.substr($digits, $decimalPosition);
    }

    private function displayMoney(string $value): string
    {
        $display = rtrim(rtrim($value, '0'), '.');

        return $display === '' ? '0' : $display;
    }
}
