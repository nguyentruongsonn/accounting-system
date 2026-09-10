<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnexpectedValueException;

class CashReportService
{
    public const CODES = ['S03a1-DNN', 'S03a2-DNN', 'CA-01', 'CA-02', 'CA-03'];
    private const MAX_SAFE_INTEGER = 9007199254740991;

    private readonly CashReportMovementSource $source;

    /** Optional to preserve the existing controller-boundary service double. */
    public function __construct(?CashReportMovementSource $source = null)
    {
        $this->source = $source ?? app(CashReportMovementSource::class);
    }

    /** @param array{date_from:string,date_to:string,status?:string,search?:string,cash_account?:?string} $filters */
    public function generate(int $companyId, string $code, array $filters): array
    {
        $this->assertSupportedCode($code);
        $filters = $this->normaliseFilters($filters);
        if (in_array($code, ['CA-01', 'CA-02', 'CA-03'], true) && $filters['status'] !== 'posted') {
            throw ValidationException::withMessages(['status' => 'Báo cáo số dư chỉ nhận chứng từ đã ghi sổ.']);
        }

        $movements = $this->source->collect($companyId, $filters, in_array($code, ['CA-01', 'CA-03'], true));

        return match ($code) {
            'S03a1-DNN' => $this->receiptJournal($movements, $filters),
            'S03a2-DNN' => $this->paymentJournal($movements, $filters),
            'CA-01' => $this->dailyBalance($movements, $filters),
            'CA-02' => $this->cashFlow($movements, $filters),
            'CA-03' => $this->detailedLedger($movements, $filters),
        };
    }

    /** @param Collection<int,array<string,int|string>> $movements */
    private function receiptJournal(Collection $movements, array $filters): array
    {
        $rows = $this->visibleMovements($movements, $filters)->filter(fn (array $m): bool => $m['source'] === 'receipt')
            ->map(fn (array $m): array => $this->journalRow($m))->values()->all();

        return ['report' => $this->report('S03a1-DNN', 'Sổ nhật ký thu tiền', $filters), 'columns' => $this->journalColumns(), 'summary' => [
            'row_count' => count($rows), 'total_receipts' => $this->sumRows($rows, 'amount'),
        ], 'rows' => $rows];
    }

    /** @param Collection<int,array<string,int|string>> $movements */
    private function paymentJournal(Collection $movements, array $filters): array
    {
        $rows = $this->visibleMovements($movements, $filters)->filter(fn (array $m): bool => $m['source'] === 'payment')
            ->map(fn (array $m): array => $this->journalRow($m))->values()->all();

        return ['report' => $this->report('S03a2-DNN', 'Sổ nhật ký chi tiền', $filters), 'columns' => $this->journalColumns(), 'summary' => [
            'row_count' => count($rows), 'total_payments' => $this->sumRows($rows, 'amount'),
        ], 'rows' => $rows];
    }

    /** @param Collection<int,array<string,int|string>> $movements */
    private function dailyBalance(Collection $movements, array $filters): array
    {
        [$opening, $period] = $this->openingAndPeriod($movements, $filters);
        $receipts = 0;
        $payments = 0;
        $balance = $opening;
        $daily = [];
        foreach ($period as $movement) {
            $date = (string) $movement['posting_date'];
            if (! isset($daily[$date])) {
                $daily[$date] = ['opening_balance' => $balance, 'total_receipts' => 0, 'total_payments' => 0, 'movements' => []];
            }
            $amount = $this->amount($movement);
            if ($movement['source'] === 'receipt') {
                $daily[$date]['total_receipts'] = $this->add($daily[$date]['total_receipts'], $amount);
                $receipts = $this->add($receipts, $amount);
                $balance = $this->add($balance, $amount);
            } else {
                $daily[$date]['total_payments'] = $this->add($daily[$date]['total_payments'], $amount);
                $payments = $this->add($payments, $amount);
                $balance = $this->add($balance, $this->negate($amount));
            }
            $daily[$date]['movements'][] = $movement;
            $daily[$date]['closing_balance'] = $balance;
        }

        $rows = [];
        foreach ($daily as $date => $day) {
            if (! $this->dayMatchesSearch($day['movements'], $filters['search'])) {
                continue;
            }
            $rows[] = ['key' => 'CA-01:' . $date, 'posting_date' => $date, 'opening_balance' => $day['opening_balance'],
                'total_receipts' => $day['total_receipts'], 'total_payments' => $day['total_payments'], 'closing_balance' => $day['closing_balance']];
        }

        return ['report' => $this->report('CA-01', 'Bảng kê số dư tiền theo ngày', $filters), 'columns' => [
            $this->column('posting_date', 'Ngày ghi sổ', 'date'), $this->column('opening_balance', 'Số dư đầu ngày', 'money'),
            $this->column('total_receipts', 'Thu trong ngày', 'money'), $this->column('total_payments', 'Chi trong ngày', 'money'),
            $this->column('closing_balance', 'Số dư cuối ngày', 'money'),
        ], 'summary' => ['opening_balance' => $opening, 'total_receipts' => $receipts, 'total_payments' => $payments, 'closing_balance' => $balance], 'rows' => $rows];
    }

    /** @param Collection<int,array<string,int|string>> $movements */
    private function cashFlow(Collection $movements, array $filters): array
    {
        $groups = [];
        $receipts = 0;
        $payments = 0;
        foreach ($this->visibleMovements($movements, $filters) as $movement) {
            $direction = (string) $movement['source'];
            $date = (string) $movement['posting_date'];
            $key = $date . ':' . $direction;
            $groups[$key] ??= ['posting_date' => $date, 'direction' => $direction, 'voucher_ids' => [], 'amount' => 0];
            $amount = $this->amount($movement);
            $groups[$key]['amount'] = $this->add($groups[$key]['amount'], $amount);
            $groups[$key]['voucher_ids'][$movement['source'] . ':' . $movement['voucher_id']] = true;
            if ($direction === 'receipt') {
                $receipts = $this->add($receipts, $amount);
            } else {
                $payments = $this->add($payments, $amount);
            }
        }
        $rows = array_values(array_map(static fn (array $g): array => ['key' => 'CA-02:' . $g['posting_date'] . ':' . $g['direction'],
            'posting_date' => $g['posting_date'], 'direction' => $g['direction'], 'transaction_count' => count($g['voucher_ids']), 'amount' => $g['amount']], $groups));
        usort($rows, static function (array $a, array $b): int {
            $date = strcmp($a['posting_date'], $b['posting_date']);
            return $date !== 0 ? $date : (['receipt' => 0, 'payment' => 1][$a['direction']] <=> ['receipt' => 0, 'payment' => 1][$b['direction']]);
        });

        return ['report' => $this->report('CA-02', 'Dòng tiền', $filters), 'columns' => [
            $this->column('posting_date', 'Ngày ghi sổ', 'date'), $this->column('direction', 'Loại thu chi', 'text'),
            $this->column('transaction_count', 'Số giao dịch', 'number'), $this->column('amount', 'Số tiền', 'money'),
        ], 'summary' => ['total_receipts' => $receipts, 'total_payments' => $payments,
            'net_cash_flow' => $this->add($receipts, $this->negate($payments))], 'rows' => $rows];
    }

    /** @param Collection<int,array<string,int|string>> $movements */
    private function detailedLedger(Collection $movements, array $filters): array
    {
        [$opening, $period] = $this->openingAndPeriod($movements, $filters);
        $receipts = 0;
        $payments = 0;
        $balance = $opening;
        $rows = [];
        foreach ($period as $movement) {
            $amount = $this->amount($movement);
            $receipt = $movement['source'] === 'receipt';
            if ($receipt) {
                $receipts = $this->add($receipts, $amount);
                $balance = $this->add($balance, $amount);
            } else {
                $payments = $this->add($payments, $amount);
                $balance = $this->add($balance, $this->negate($amount));
            }
            if (! $this->source->matchesSearch($movement, $filters['search'])) {
                continue;
            }
            $rows[] = ['key' => $this->movementKey('CA-03', $movement), 'posting_date' => $movement['posting_date'],
                'voucher_date' => $movement['voucher_date'], 'voucher_number' => $movement['voucher_number'], 'direction' => $movement['source'],
                'contact_name' => $movement['contact_name'], 'description' => $movement['description'], 'cash_account' => $movement['cash_account'],
                'counterpart_account' => $movement['counterpart_account'], 'receipt_amount' => $receipt ? $amount : 0,
                'payment_amount' => $receipt ? 0 : $amount, 'running_balance' => $balance,
                'source_type' => $movement['source'], 'source_id' => $movement['voucher_id']];
        }

        return ['report' => $this->report('CA-03', 'Sổ kế toán chi tiết quỹ tiền mặt', $filters), 'columns' => [
            $this->column('posting_date', 'Ngày ghi sổ', 'date'), $this->column('voucher_date', 'Ngày chứng từ', 'date'),
            $this->column('voucher_number', 'Số chứng từ', 'text'), $this->column('direction', 'Loại thu chi', 'text'),
            $this->column('contact_name', 'Đối tượng', 'text'), $this->column('description', 'Diễn giải', 'text'),
            $this->column('cash_account', 'Tài khoản tiền', 'text'), $this->column('counterpart_account', 'Tài khoản đối ứng', 'text'),
            $this->column('receipt_amount', 'Thu', 'money'), $this->column('payment_amount', 'Chi', 'money'),
            $this->column('running_balance', 'Số dư', 'money'),
        ], 'summary' => ['opening_balance' => $opening, 'total_receipts' => $receipts, 'total_payments' => $payments,
            'closing_balance' => $balance], 'rows' => $rows];
    }

    /** @return array{0:int,1:list<array<string,int|string>>} */
    private function openingAndPeriod(Collection $movements, array $filters): array
    {
        $opening = 0;
        $period = [];
        foreach ($movements as $movement) {
            if ($movement['posting_date'] < $filters['date_from']) {
                $opening = $this->applyMovement($opening, $movement);
            } else {
                $period[] = $movement;
            }
        }
        return [$opening, $period];
    }

    /** @param array<string,int|string> $movement */
    private function journalRow(array $movement): array
    {
        return ['key' => $this->movementKey('journal', $movement), 'posting_date' => $movement['posting_date'], 'voucher_date' => $movement['voucher_date'],
            'voucher_number' => $movement['voucher_number'], 'contact_name' => $movement['contact_name'], 'description' => $movement['description'],
            'cash_account' => $movement['cash_account'], 'counterpart_account' => $movement['counterpart_account'],
            'amount' => $this->amount($movement), 'status' => $movement['status'],
            'source_type' => $movement['source'], 'source_id' => $movement['voucher_id']];
    }

    /** @param array<string,int|string> $movement */
    private function applyMovement(int $balance, array $movement): int
    {
        return $movement['source'] === 'receipt' ? $this->add($balance, $this->amount($movement)) : $this->add($balance, $this->negate($this->amount($movement)));
    }

    private function visibleMovements(Collection $movements, array $filters): Collection
    {
        return $movements->filter(fn (array $m): bool => $this->source->matchesSearch($m, $filters['search']))->values();
    }

    /** @param list<array<string,int|string>> $movements */
    private function dayMatchesSearch(array $movements, string $search): bool
    {
        foreach ($movements as $movement) {
            if ($this->source->matchesSearch($movement, $search)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string,int|string>> $rows */
    private function sumRows(array $rows, string $field): int
    {
        $sum = 0;
        foreach ($rows as $row) {
            $sum = $this->add($sum, $this->amount(['amount' => $row[$field]]));
        }
        return $sum;
    }

    /** @param array<string,int|string> $movement */
    private function movementKey(string $prefix, array $movement): string
    {
        return implode(':', [$prefix, $movement['source'], $movement['voucher_id'], $movement['line_id']]);
    }

    /** @param array<string,int|string> $movement */
    private function amount(array $movement): int
    {
        $amount = $movement['amount'] ?? null;
        if (! is_int($amount) || $amount < -self::MAX_SAFE_INTEGER || $amount > self::MAX_SAFE_INTEGER) {
            throw new UnexpectedValueException('Cash report amount must be a safe integer VND amount.');
        }
        return $amount;
    }

    private function add(int $left, int $right): int
    {
        $this->assertSafe($left);
        $this->assertSafe($right);
        if (($right > 0 && $left > self::MAX_SAFE_INTEGER - $right) || ($right < 0 && $left < -self::MAX_SAFE_INTEGER - $right)) {
            throw new UnexpectedValueException('Cash report total exceeds the safe integer VND range.');
        }
        return $left + $right;
    }

    private function negate(int $value): int
    {
        $this->assertSafe($value);
        return -$value;
    }

    private function assertSafe(int $value): void
    {
        if ($value < -self::MAX_SAFE_INTEGER || $value > self::MAX_SAFE_INTEGER) {
            throw new UnexpectedValueException('Cash report total exceeds the safe integer VND range.');
        }
    }

    /** @return array{key:string,label:string,type:string} */
    private function column(string $key, string $label, string $type): array
    {
        return compact('key', 'label', 'type');
    }

    /** @return list<array{key:string,label:string,type:string}> */
    private function journalColumns(): array
    {
        return [$this->column('posting_date', 'Ngày ghi sổ', 'date'), $this->column('voucher_date', 'Ngày chứng từ', 'date'),
            $this->column('voucher_number', 'Số chứng từ', 'text'), $this->column('contact_name', 'Đối tượng', 'text'),
            $this->column('description', 'Diễn giải', 'text'), $this->column('cash_account', 'Tài khoản tiền', 'text'),
            $this->column('counterpart_account', 'Tài khoản đối ứng', 'text'), $this->column('amount', 'Số tiền', 'money'),
            $this->column('status', 'Trạng thái', 'status')];
    }

    private function report(string $code, string $name, array $filters): array
    {
        return ['code' => $code, 'name' => $name, 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'],
            'status' => $filters['status'], 'cash_account' => $filters['cash_account'], 'search' => $filters['search']];
    }

    /** @param array<string,mixed> $filters */
    private function normaliseFilters(array $filters): array
    {
        $status = $filters['status'] ?? 'posted';
        if (! is_string($status) || ! in_array($status, ['posted', 'draft', 'voided', 'all'], true)) {
            throw ValidationException::withMessages(['status' => 'Cash report status filter is invalid.']);
        }
        $search = $filters['search'] ?? '';
        if (! is_string($search)) {
            throw ValidationException::withMessages(['search' => 'Cash report search filter is invalid.']);
        }
        $cashAccount = $filters['cash_account'] ?? null;
        if ($cashAccount !== null && (! is_string($cashAccount) || ! preg_match('/^111[0-9]*$/D', $cashAccount))) {
            throw ValidationException::withMessages(['cash_account' => 'Cash report cash account filter is invalid.']);
        }
        foreach (['date_from', 'date_to'] as $field) {
            if (! isset($filters[$field]) || ! is_string($filters[$field])) {
                throw new InvalidArgumentException("Cash report {$field} filter is invalid.");
            }
        }
        return ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to'], 'status' => $status,
            'search' => trim($search), 'cash_account' => $cashAccount];
    }

    private function assertSupportedCode(string $code): void
    {
        if (! in_array($code, self::CODES, true)) {
            throw ValidationException::withMessages(['code' => 'Unsupported cash report code.']);
        }
    }
}
