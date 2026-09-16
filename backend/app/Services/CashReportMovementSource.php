<?php

namespace App\Services;

use App\Models\CashPayment;
use App\Models\CashReceipt;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnexpectedValueException;

final class CashReportMovementSource
{
    private const MAX_SAFE_INTEGER = '9007199254740991';

    /**
     * @param array{date_from: string, date_to: string, status: string, search: string, cash_account: ?string} $filters
     * @return Collection<int, array<string, int|string>>
     */
    public function collect(int $companyId, array $filters, bool $includeBeforePeriod = false): Collection
    {
        $status = $this->requestedStatus($filters);
        $cashAccount = $filters['cash_account'] ?? null;

        $receipts = $this->voucherQuery(CashReceipt::query(), $companyId, $filters, $status, $includeBeforePeriod)
            ->with('lines')
            ->get();
        $payments = $this->voucherQuery(CashPayment::query(), $companyId, $filters, $status, $includeBeforePeriod)
            ->with('lines')
            ->get();

        return $receipts
            ->flatMap(fn (CashReceipt $receipt): array => $this->normaliseVoucher($receipt, 'receipt', $status, $cashAccount))
            ->concat($payments->flatMap(fn (CashPayment $payment): array => $this->normaliseVoucher($payment, 'payment', $status, $cashAccount)))
            ->sort(function (array $left, array $right): int {
                foreach (['posting_date', 'voucher_date', 'voucher_number', 'source'] as $field) {
                    $comparison = strcmp((string) $left[$field], (string) $right[$field]);
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return $left['line_id'] <=> $right['line_id'];
            })
            ->values();
    }

    /** @param array<string, int|string> $movement */
    public function matchesSearch(array $movement, string $search): bool
    {
        $needle = trim($search);

        return $needle === '' || str_contains(
            Str::lower((string) ($movement['search_text'] ?? '')),
            Str::lower($needle),
        );
    }

    /**
     * @param Builder<Model> $query
     * @param array{date_from: string, date_to: string, status: string, search: string, cash_account: ?string} $filters
     * @return Builder<Model>
     */
    private function voucherQuery(Builder $query, int $companyId, array $filters, string $status, bool $includeBeforePeriod): Builder
    {
        $model = $query->getModel();
        $table = $model->getTable();

        $query
            ->where("{$table}.company_id", $companyId)
            ->whereNull("{$table}.deleted_at");

        $postingDate = "{$table}.posting_date";
        $query->where(function (Builder $query) use ($postingDate, $filters, $includeBeforePeriod): void {
            $query->whereRaw("DATE({$postingDate}) IS NULL")
                ->orWhere(function (Builder $query) use ($postingDate, $filters, $includeBeforePeriod): void {
                    $query
                        ->whereRaw("DATE({$postingDate}) IS NOT NULL")
                        ->whereRaw("DATE({$postingDate}) <= ?", [$filters['date_to']]);

                    if (! $includeBeforePeriod) {
                        $query->whereRaw("DATE({$postingDate}) >= ?", [$filters['date_from']]);
                    }
                });
        });

        if ($status === 'posted') {
            $query->where("{$table}.is_posted", true)->where("{$table}.status", '!=', 'voided');
        } elseif ($status === 'draft') {
            $query
                ->where("{$table}.status", '!=', 'voided')
                ->where(function (Builder $query) use ($table): void {
                    $query->where("{$table}.is_posted", false)->orWhereNull("{$table}.is_posted");
                });
        } elseif ($status === 'voided') {
            $query->where("{$table}.status", 'voided');
        }

        return $query;
    }

    /**
     * @param 'receipt'|'payment' $source
     * @return list<array<string, int|string>>
     */
    private function normaliseVoucher(Model $voucher, string $source, string $requestedStatus, ?string $cashAccountFilter): array
    {
        $status = $this->effectiveStatus($voucher);
        if ($requestedStatus !== 'all' && $requestedStatus !== $status) {
            return [];
        }

        $postingDate = $this->normaliseDate($voucher, 'posting_date');
        $voucherDate = $this->normaliseDate($voucher, 'voucher_date');
        $voucherNumber = $this->stringValue($voucher->getAttribute('voucher_number'));
        $reason = $this->stringValue($voucher->getAttribute('reason'));
        $contact = $this->contactName($voucher, $source);
        $movements = [];

        foreach ($voucher->lines as $line) {
            $debit = $this->stringValue($line->getAttribute('debit_account'));
            $credit = $this->stringValue($line->getAttribute('credit_account'));
            $touchesBank = str_starts_with($debit, '112') || str_starts_with($credit, '112');
            $isCashLine = $source === 'receipt'
                ? str_starts_with($debit, '111') && ! $touchesBank
                : str_starts_with($credit, '111') && ! $touchesBank;

            if (! $isCashLine) {
                continue;
            }

            $cashAccount = $source === 'receipt' ? $debit : $credit;
            if ($cashAccountFilter !== null && ! str_starts_with($cashAccount, $cashAccountFilter)) {
                continue;
            }

            $description = $this->stringValue($line->getAttribute('description'));
            $lineId = $line->getKey();
            if (! is_int($lineId) && ! ctype_digit((string) $lineId)) {
                throw new UnexpectedValueException('Cash report line_id must be an integer.');
            }

            $movements[] = [
                'source' => $source,
                'voucher_id' => (int) $voucher->getKey(),
                'line_id' => (int) $lineId,
                'posting_date' => $postingDate,
                'voucher_date' => $voucherDate,
                'voucher_number' => $voucherNumber,
                'contact_name' => $contact,
                'description' => $description !== '' ? $description : $reason,
                'cash_account' => $cashAccount,
                'counterpart_account' => $source === 'receipt' ? $credit : $debit,
                'direction' => $source === 'receipt' ? 'in' : 'out',
                'amount' => $this->safeAmount($line->getRawOriginal('amount')),
                'status' => $status,
                'search_text' => implode("\n", [$voucherNumber, $contact, $reason, $description, $debit, $credit]),
            ];
        }

        return $movements;
    }

    private function effectiveStatus(Model $voucher): string
    {
        if (Str::lower($this->stringValue($voucher->getRawOriginal('status'))) === 'voided') {
            return 'voided';
        }

        return (bool) $voucher->getAttribute('is_posted') ? 'posted' : 'draft';
    }

    private function contactName(Model $voucher, string $source): string
    {
        $storedContact = $this->stringValue($voucher->getAttribute('contact_name'));
        if ($storedContact !== '') {
            return $storedContact;
        }

        return $this->stringValue($voucher->getAttribute($source === 'receipt' ? 'payer_name' : 'receiver_name'));
    }

    private function normaliseDate(Model $voucher, string $field): string
    {
        $raw = $voucher->getRawOriginal($field);
        if (! is_string($raw) || trim($raw) === '') {
            throw new UnexpectedValueException("Cash report {$field} must be a valid date.");
        }

        foreach (['!Y-m-d', '!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, trim($raw));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        throw new UnexpectedValueException("Cash report {$field} must be a valid date.");
    }

    private function safeAmount(mixed $rawAmount): int
    {
        if (is_int($rawAmount)) {
            if (abs($rawAmount) <= (int) self::MAX_SAFE_INTEGER) {
                return $rawAmount;
            }

            throw new UnexpectedValueException('Cash report line amount must be a safe integer VND amount.');
        }

        if (! is_string($rawAmount) || ! preg_match('/^[+-]?\d+$/D', $rawAmount)) {
            throw new UnexpectedValueException('Cash report line amount must be a safe integer VND amount.');
        }

        $unsigned = ltrim(ltrim($rawAmount, '+-'), '0');
        if ($unsigned === '') {
            return 0;
        }
        if (strlen($unsigned) > strlen(self::MAX_SAFE_INTEGER)
            || (strlen($unsigned) === strlen(self::MAX_SAFE_INTEGER) && strcmp($unsigned, self::MAX_SAFE_INTEGER) > 0)) {
            throw new UnexpectedValueException('Cash report line amount must be a safe integer VND amount.');
        }

        return (int) $rawAmount;
    }

    /** @param array<string, mixed> $filters */
    private function requestedStatus(array $filters): string
    {
        $status = $filters['status'] ?? 'posted';
        if (! is_string($status) || ! in_array($status, ['posted', 'draft', 'voided', 'all'], true)) {
            throw new InvalidArgumentException('Cash report status filter is invalid.');
        }

        return $status;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }
}
