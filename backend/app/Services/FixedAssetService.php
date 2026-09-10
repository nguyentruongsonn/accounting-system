<?php

namespace App\Services;

use App\Models\AssetDisposal;
use App\Models\AssetRevaluation;
use App\Models\DepreciationLog;
use App\Models\DepreciationLogLine;
use App\Models\FixedAsset;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FixedAssetService
{
    protected JournalEntryService $journalEntryService;

    public function __construct(
        JournalEntryService $journalEntryService,
        private readonly AuditService $auditService,
        private readonly FixedAssetPeriodMutationGuard $periodMutationGuard,
    )
    {
        $this->journalEntryService = $journalEntryService;
    }

    /**
     * Lấy danh sách tài sản cố định kèm bộ lọc
     */
    public function getAll($filters = [])
    {
        if (is_numeric($filters)) {
            $filters = ['company_id' => $filters];
        } elseif (! is_array($filters)) {
            $filters = [];
        }

        // A direct service caller must never receive an unscoped asset list.
        // HTTP controllers already provide this filter from TenantContext;
        // resolving it again here protects queue/CLI and future callers.
        $filters['company_id'] = $this->requireCompanyId(
            isset($filters['company_id']) ? (int) $filters['company_id'] : null,
        );

        $query = FixedAsset::with(['supplier', 'journalEntry']);

        $query->where('company_id', $filters['company_id']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['department_code'])) {
            $query->where('department_code', $filters['department_code']);
        }

        if (! empty($filters['category_code'])) {
            $query->where('category_code', $filters['category_code']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['from_date'])) {
            $query->where('purchase_date', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->where('purchase_date', '<=', $filters['to_date']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('asset_code', 'like', "%{$search}%")
                    ->orWhere('asset_name', 'like', "%{$search}%")
                    ->orWhere('voucher_number', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('id');

        if (! empty($filters['per_page'])) {
            return $query->paginate((int) $filters['per_page']);
        }

        return $query->get();
    }

    /**
     * Lấy chi tiết tài sản cố định
     */
    public function getById(int $companyId, int $id): FixedAsset
    {
        $companyId = $this->requireCompanyId($companyId);

        return FixedAsset::where('company_id', $companyId)->with([
            'supplier',
            'journalEntry.lines',
            'depreciationLines.depreciationLog',
            'disposals',
            'revaluations',
            'references',
            'referencedBy',
        ])->findOrFail($id);
    }

    /**
     * Sinh mã tự động tiếp theo cho chứng từ / tài sản
     */
    public function generateNextCode(?int $companyId = null, string $type = 'TSCD'): string
    {
        $companyId = $this->requireCompanyId($companyId);
        $year = now()->format('Y');

        return match (strtoupper($type)) {
            'TS', 'ASSET' => $this->generateSequentialCode(FixedAsset::class, 'asset_code', 'TS', $companyId, 5),
            'TSCD' => $this->generateYearlyCode(FixedAsset::class, 'voucher_number', "TSCD-{$year}-", $companyId),
            'KHTS' => 'KHTS-'.now()->format('Y-m'),
            'GGTS', 'DISPOSAL' => $this->generateYearlyCode(AssetDisposal::class, 'voucher_number', "GGTS-{$year}-", $companyId),
            'DGTS', 'REVALUATION' => $this->generateYearlyCode(AssetRevaluation::class, 'voucher_number', "DGTS-{$year}-", $companyId),
            default => $this->generateYearlyCode(FixedAsset::class, 'voucher_number', "{$type}-{$year}-", $companyId),
        };
    }

    protected function generateYearlyCode(string $modelClass, string $column, string $prefix, int $companyId): string
    {
        $latest = $modelClass::where('company_id', $companyId)
            ->where($column, 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value($column);

        if ($latest && preg_match('/'.preg_quote($prefix, '/').'(\d+)/', $latest, $m)) {
            $seq = str_pad((int) $m[1] + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $count = $modelClass::where('company_id', $companyId)
                ->where($column, 'like', $prefix.'%')
                ->count() + 1;
            $seq = str_pad($count, 4, '0', STR_PAD_LEFT);
        }

        return $prefix.$seq;
    }

    protected function generateSequentialCode(string $modelClass, string $column, string $prefix, int $companyId, int $padLength = 5): string
    {
        $latest = $modelClass::where('company_id', $companyId)
            ->where($column, 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value($column);

        if ($latest && preg_match('/'.preg_quote($prefix, '/').'(\d+)/', $latest, $m)) {
            $seq = str_pad((int) $m[1] + 1, $padLength, '0', STR_PAD_LEFT);
        } else {
            $count = $modelClass::where('company_id', $companyId)->count() + 1;
            $seq = str_pad($count, $padLength, '0', STR_PAD_LEFT);
        }

        return $prefix.$seq;
    }

    /**
     * Tạo mới tài sản cố định
     */
    public function createAsset(array $data): FixedAsset
    {
        return DB::transaction(function () use ($data) {
            $companyId = $this->requireCompanyId($data['company_id'] ?? null);
            $this->periodMutationGuard->assertAssetCreate((int) $companyId, $data);
            $originalCost = floatval($data['original_cost'] ?? 0);
            $depreciableCost = isset($data['depreciable_cost']) ? floatval($data['depreciable_cost']) : $originalCost;
            $usefulLifeMonths = intval($data['useful_life_months'] ?? 0);
            $accumulatedDepreciation = floatval($data['accumulated_depreciation'] ?? 0);

            $monthlyDepreciation = 0;
            if ($usefulLifeMonths > 0) {
                $monthlyDepreciation = round($depreciableCost / $usefulLifeMonths);
            }

            $netValue = $depreciableCost - $accumulatedDepreciation;
            if ($netValue < 0) {
                $netValue = 0;
            }

            $assetCode = $data['asset_code'] ?? $this->generateNextCode($companyId, 'TS');
            $voucherNumber = $data['voucher_number'] ?? $this->generateNextCode($companyId, 'TSCD');
            $voucherDate = $data['voucher_date'] ?? $data['purchase_date'] ?? now()->toDateString();
            $purchaseDate = $data['purchase_date'] ?? now()->toDateString();
            $startDepreciationDate = $data['start_depreciation_date'] ?? $purchaseDate;

            $isPosted = ! empty($data['is_posted']) || ($data['status'] ?? '') === 'posted';
            $status = $isPosted ? 'posted' : ($data['status'] ?? 'draft');

            // Legacy asset account defaults are not owner-approved mappings.
            // Keep posted GL creation fail-closed; draft capture remains available.
            if ($isPosted) {
                $this->assertFixedAssetPostingMappingsAvailable();
                if ($originalCost <= 0) {
                    throw ValidationException::withMessages([
                        'original_cost' => 'Tài sản chỉ được ghi sổ khi có nguyên giá dương và bút toán GL tương ứng.',
                    ]);
                }
            }

            $asset = FixedAsset::create([
                'company_id' => $companyId,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $voucherDate,
                'asset_code' => $assetCode,
                'asset_name' => $data['asset_name'],
                'category_code' => $data['category_code'] ?? null,
                'department_code' => $data['department_code'] ?? null,
                'quantity' => intval($data['quantity'] ?? 1),
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'purchase_date' => $purchaseDate,
                'start_depreciation_date' => $startDepreciationDate,
                'original_cost' => $originalCost,
                'depreciable_cost' => $depreciableCost,
                'useful_life_months' => $usefulLifeMonths,
                'monthly_depreciation' => $monthlyDepreciation,
                'accumulated_depreciation' => $accumulatedDepreciation,
                'net_value' => $netValue,
                'asset_account' => $data['asset_account'] ?? '211',
                'depreciation_account' => $data['depreciation_account'] ?? '2141',
                'expense_account' => $data['expense_account'] ?? $this->resolveExpenseAccount($data['department_code'] ?? null),
                'credit_account' => $data['credit_account'] ?? '331',
                'is_active' => true,
                'is_posted' => $isPosted,
                'status' => $status,
                'referenced_vouchers' => $data['referenced_vouchers'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // Sinh bút toán ghi tăng — CHỈ khi is_posted=true tường minh trong request
            $creditAccount = $data['credit_account'] ?? '331';
            if ($isPosted) {
                if ($originalCost > 0) {
                    $je = $this->journalEntryService->createPosted([
                        'company_id' => $companyId,
                        'voucher_type' => 'fixed_asset_increment',
                        'voucher_number' => 'GL-FA-'.($asset->voucher_number ?? time()),
                        'voucher_date' => $asset->voucher_date ?? $asset->purchase_date,
                        'posting_date' => $asset->voucher_date ?? $asset->purchase_date,
                        'description' => 'Ghi tăng TSCĐ: '.$asset->asset_name,
                        'total_amount' => 0,
                        'status' => 'posted',
                        'source_document_type' => FixedAsset::class,
                        'source_document_id' => $asset->id,
                        'lines' => [
                            [
                                'account_code' => $asset->asset_account ?: '211',
                                'description' => 'Ghi tăng TSCĐ: '.$asset->asset_name,
                                'debit_amount' => $asset->original_cost,
                                'credit_amount' => 0,
                            ],
                            [
                                'account_code' => $creditAccount,
                                'description' => 'Ghi tăng TSCĐ: '.$asset->asset_name,
                                'debit_amount' => 0,
                                'credit_amount' => $asset->original_cost,
                            ],
                        ],
                    ]);

                    $asset->journal_entry_id = $je->id;
                    $asset->is_posted = true;
                    $asset->status = 'posted';
                    $asset->save();
                }
            }

            if (! empty($data['referenced_vouchers'])) {
                $asset->syncReferences($data['referenced_vouchers']);
            }

            $this->recordAudit($asset, 'fixed_asset.created', [], $asset->getAttributes());

            return $asset->load(['supplier', 'journalEntry']);
        });
    }

    /**
     * Cập nhật thông tin tài sản cố định
     */
    public function updateAsset(int $companyId, int $id, array $data): FixedAsset
    {
        return DB::transaction(function () use ($companyId, $id, $data) {
            $asset = $this->assetForCompany($companyId, $id);
            $this->periodMutationGuard->assertAssetUpdate($asset, $data);
            $before = $asset->getAttributes();

            // Ràng buộc: Không đổi nguyên giá trực tiếp nếu đã có khấu hao
            $hasDepreciation = floatval($asset->accumulated_depreciation) > 0 || $asset->depreciationLines()->count() > 0;
            if ($hasDepreciation && isset($data['original_cost']) && floatval($data['original_cost']) != floatval($asset->original_cost)) {
                throw new Exception('Không thể thay đổi nguyên giá tài sản đã phát sinh khấu hao. Vui lòng sử dụng chức năng Đánh giá lại TSCĐ.');
            }

            $originalCost = isset($data['original_cost']) ? floatval($data['original_cost']) : floatval($asset->original_cost);
            $depreciableCost = isset($data['depreciable_cost']) ? floatval($data['depreciable_cost']) : floatval($asset->depreciable_cost);
            $usefulLifeMonths = isset($data['useful_life_months']) ? intval($data['useful_life_months']) : $asset->useful_life_months;

            $monthlyDepreciation = $usefulLifeMonths > 0 ? round($depreciableCost / $usefulLifeMonths) : 0;
            $accumulatedDepreciation = isset($data['accumulated_depreciation']) ? floatval($data['accumulated_depreciation']) : floatval($asset->accumulated_depreciation);
            $netValue = $depreciableCost - $accumulatedDepreciation;

            $asset->fill([
                'asset_name' => $data['asset_name'] ?? $asset->asset_name,
                'category_code' => $data['category_code'] ?? $asset->category_code,
                'department_code' => $data['department_code'] ?? $asset->department_code,
                'quantity' => isset($data['quantity']) ? intval($data['quantity']) : $asset->quantity,
                'supplier_id' => $data['supplier_id'] ?? $asset->supplier_id,
                'supplier_name' => $data['supplier_name'] ?? $asset->supplier_name,
                'purchase_date' => $data['purchase_date'] ?? $asset->purchase_date,
                'start_depreciation_date' => $data['start_depreciation_date'] ?? $asset->start_depreciation_date,
                'original_cost' => $originalCost,
                'depreciable_cost' => $depreciableCost,
                'useful_life_months' => $usefulLifeMonths,
                'monthly_depreciation' => $monthlyDepreciation,
                'accumulated_depreciation' => $accumulatedDepreciation,
                'net_value' => $netValue,
                'asset_account' => $data['asset_account'] ?? $asset->asset_account,
                'depreciation_account' => $data['depreciation_account'] ?? $asset->depreciation_account,
                'expense_account' => $data['expense_account'] ?? $asset->expense_account,
                'credit_account' => $data['credit_account'] ?? $asset->credit_account,
                'voucher_number' => $data['voucher_number'] ?? $asset->voucher_number,
                'voucher_date' => $data['voucher_date'] ?? $asset->voucher_date,
                'updated_by' => auth()->id(),
            ]);

            $asset->save();

            if (isset($data['referenced_vouchers'])) {
                $asset->syncReferences($data['referenced_vouchers']);
            }

            $this->recordAudit($asset, 'fixed_asset.updated', $before, $asset->getAttributes());

            return $asset->load(['supplier', 'journalEntry']);
        });
    }

    /**
     * Ghi sổ chứng từ ghi tăng TSCĐ
     */
    public function postAsset(int $companyId, int $id): FixedAsset
    {
        return DB::transaction(function () use ($companyId, $id) {
            $asset = $this->assetForCompany($companyId, $id);
            $this->periodMutationGuard->assertExistingAsset($asset, 'ghi sổ chứng từ ghi tăng tài sản cố định');
            $before = $asset->getAttributes();

            if ($asset->is_posted) {
                throw new Exception("Tài sản {$asset->asset_code} đã được ghi sổ.");
            }

            $this->assertFixedAssetPostingMappingsAvailable();

            $creditAccount = $asset->credit_account ?: '331';
            $je = $this->journalEntryService->createPosted([
                'company_id' => $asset->company_id,
                'voucher_type' => 'fixed_asset_increment',
                'voucher_number' => 'GL-FA-'.($asset->voucher_number ?? time()),
                'voucher_date' => $asset->voucher_date ?? $asset->purchase_date,
                'posting_date' => $asset->voucher_date ?? $asset->purchase_date,
                'description' => 'Ghi tăng TSCĐ: '.$asset->asset_name,
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => FixedAsset::class,
                'source_document_id' => $asset->id,
                'lines' => [
                    [
                        'account_code' => $asset->asset_account ?: '211',
                        'description' => 'Ghi tăng TSCĐ: '.$asset->asset_name,
                        'debit_amount' => $asset->original_cost,
                        'credit_amount' => 0,
                    ],
                    [
                        'account_code' => $creditAccount,
                        'description' => 'Ghi tăng TSCĐ: '.$asset->asset_name,
                        'debit_amount' => 0,
                        'credit_amount' => $asset->original_cost,
                    ],
                ],
            ]);

            $asset->journal_entry_id = $je->id;
            $asset->is_posted = true;
            $asset->status = 'posted';
            $asset->save();

            $this->recordAudit($asset, 'fixed_asset.posted', $before, $asset->getAttributes());

            return $asset->load(['supplier', 'journalEntry']);
        });
    }

    /**
     * Bỏ ghi sổ chứng từ ghi tăng TSCĐ
     */
    public function unpostAsset(int $companyId, int $id): FixedAsset
    {
        return DB::transaction(function () use ($companyId, $id) {
            $asset = $this->assetForCompany($companyId, $id);
            $this->periodMutationGuard->assertExistingAsset($asset, 'bỏ ghi sổ chứng từ ghi tăng tài sản cố định');
            $before = $asset->getAttributes();

            if (! $asset->is_posted) {
                throw new Exception("Tài sản {$asset->asset_code} chưa được ghi sổ.");
            }

            if ($asset->depreciationLines()->count() > 0) {
                throw new Exception('Không thể bỏ ghi sổ tài sản đã phát sinh khấu hao. Hãy hủy các chứng từ khấu hao liên quan trước.');
            }

            if ($asset->disposals()->count() > 0) {
                throw new Exception('Không thể bỏ ghi sổ tài sản đã thanh lý. Hãy hủy chứng từ thanh lý trước.');
            }

            if ($asset->journal_entry_id) {
                $this->journalEntryService->void($asset->journal_entry_id, $companyId);
            }

            $asset->is_posted = false;
            $asset->status = 'draft';
            $asset->save();

            $this->recordAudit($asset, 'fixed_asset.unposted', $before, $asset->getAttributes());

            return $asset->load(['supplier', 'journalEntry']);
        });
    }

    /**
     * Nhân bản tài sản cố định
     */
    public function duplicateAsset(int $companyId, int $id): FixedAsset
    {
        return DB::transaction(function () use ($companyId, $id) {
            $original = $this->assetForCompany($companyId, $id);
            $this->periodMutationGuard->assertDuplicate($original);

            $newAssetCode = $this->generateNextCode($original->company_id, 'TS');
            $newVoucherNumber = $this->generateNextCode($original->company_id, 'TSCD');

            $newAsset = $original->replicate();
            $newAsset->asset_code = $newAssetCode;
            $newAsset->voucher_number = $newVoucherNumber;
            $newAsset->voucher_date = now()->toDateString();
            $newAsset->purchase_date = now()->toDateString();
            $newAsset->start_depreciation_date = now()->toDateString();
            $newAsset->accumulated_depreciation = 0;
            $newAsset->net_value = $original->original_cost;
            $newAsset->is_posted = false;
            $newAsset->status = 'draft';
            $newAsset->journal_entry_id = null;
            $newAsset->disposal_date = null;
            $newAsset->disposal_reason = null;
            $newAsset->is_active = true;
            $newAsset->save();

            $this->recordAudit($newAsset, 'fixed_asset.duplicated', [], $newAsset->getAttributes(), ['source_fixed_asset_id' => $original->id]);

            return $newAsset->load(['supplier']);
        });
    }

    /**
     * Xóa tài sản cố định
     */
    public function deleteAsset(int $companyId, int $id): void
    {
        DB::transaction(function () use ($companyId, $id) {
            $asset = $this->assetForCompany($companyId, $id);
            $this->periodMutationGuard->assertExistingAsset($asset, 'xóa chứng từ ghi tăng tài sản cố định');
            $before = $asset->getAttributes();

            if ($asset->depreciationLines()->count() > 0) {
                throw new Exception('Không thể xóa tài sản đã phát sinh khấu hao.');
            }

            if ($asset->disposals()->count() > 0) {
                throw new Exception('Không thể xóa tài sản đã thanh lý.');
            }

            // A posted fixed asset is an accounting source document. Voiding
            // its JE and deleting the register row in one operation would
            // erase the source lineage; use the explicit unpost/reversal
            // workflow before any draft deletion.
            if ($asset->is_posted) {
                throw ValidationException::withMessages([
                    'fixed_asset' => 'Không thể xóa tài sản đã ghi sổ; hãy bỏ ghi sổ hoặc lập chứng từ điều chỉnh trước.',
                ]);
            }

            if ($asset->journal_entry_id) {
                $this->journalEntryService->void($asset->journal_entry_id, $companyId);
            }

            $asset->references()->delete();
            $asset->delete();
            $this->recordAudit($asset, 'fixed_asset.deleted', $before, []);
        });
    }

    /**
     * Phân bổ tài khoản chi phí khấu hao theo bộ phận sử dụng
     */
    public function resolveExpenseAccount(?string $departmentCode, ?string $explicitExpenseAccount = null, string $standard = 'TT200'): string
    {
        if (! empty($explicitExpenseAccount)) {
            return $explicitExpenseAccount;
        }

        $is133 = strtoupper($standard) === 'TT133';
        $code = strtoupper(trim($departmentCode ?? ''));

        // Quản lý doanh nghiệp
        if (in_array($code, ['QLDN', 'BGD', 'KT', 'HC', 'VP', 'ADMIN', 'MANAGEMENT', 'VAN_PHONG', 'KE_TOAN'])) {
            return $is133 ? '6422' : '6424';
        }

        // Bán hàng & Marketing
        if (in_array($code, ['BH', 'BAN_HANG', 'KD', 'KINH_DOANH', 'MKT', 'MARKETING', 'SALES'])) {
            return $is133 ? '6421' : '6414';
        }

        // Phân xưởng / Sản xuất
        if (in_array($code, ['PX', 'SX', 'NM', 'PHAN_XUONG', 'SAN_XUAT', 'NHA_MAY', 'PX1', 'PX2', 'FACTORY', 'PRODUCTION'])) {
            return $is133 ? '154' : '154'; // Or 6274 in TT200 if configured
        }

        return $is133 ? '6422' : '6424';
    }

    /**
     * Xem trước bảng tính trích khấu hao tháng
     */
    public function previewMonthlyDepreciation(int $companyId, string $month): array
    {
        $companyId = $this->requireCompanyId($companyId);
        $monthEnd = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();

        $assets = FixedAsset::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('net_value', '>', 0)
            ->where('status', '!=', 'disposed')
            ->where('start_depreciation_date', '<=', $monthEnd)
            ->get();

        $lines = [];
        $totalAmount = 0;
        $byDepartment = [];

        foreach ($assets as $idx => $asset) {
            $depreciationAmount = floatval($asset->monthly_depreciation);
            if (floatval($asset->net_value) < $depreciationAmount) {
                $depreciationAmount = floatval($asset->net_value);
            }

            if ($depreciationAmount <= 0) {
                continue;
            }

            $totalAmount += $depreciationAmount;
            $accumulatedBefore = floatval($asset->accumulated_depreciation);
            $accumulatedAfter = $accumulatedBefore + $depreciationAmount;
            $netValueAfter = floatval($asset->original_cost) - $accumulatedAfter;
            $expenseAccount = $this->resolveExpenseAccount($asset->department_code, $asset->expense_account);
            $depreciationAccount = $asset->depreciation_account ?: '2141';

            $lines[] = [
                'line_order' => $idx + 1,
                'fixed_asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'asset_name' => $asset->asset_name,
                'department_code' => $asset->department_code,
                'category_code' => $asset->category_code,
                'original_cost' => floatval($asset->original_cost),
                'depreciable_cost' => floatval($asset->depreciable_cost),
                'useful_life_months' => $asset->useful_life_months,
                'accumulated_depreciation_before' => $accumulatedBefore,
                'monthly_depreciation' => $depreciationAmount,
                'accumulated_depreciation_after' => $accumulatedAfter,
                'net_value_after' => $netValueAfter,
                'expense_account' => $expenseAccount,
                'depreciation_account' => $depreciationAccount,
            ];

            $deptKey = $asset->department_code ?: 'UNASSIGNED';
            if (! isset($byDepartment[$deptKey])) {
                $byDepartment[$deptKey] = [
                    'department_code' => $deptKey,
                    'expense_account' => $expenseAccount,
                    'total_amount' => 0,
                    'count' => 0,
                ];
            }
            $byDepartment[$deptKey]['total_amount'] += $depreciationAmount;
            $byDepartment[$deptKey]['count']++;
        }

        return [
            'month' => $month,
            'voucher_number' => 'KHTS-'.$month,
            'voucher_date' => $monthEnd,
            'total_assets' => count($lines),
            'total_amount' => $totalAmount,
            'by_department' => array_values($byDepartment),
            'lines' => $lines,
        ];
    }

    /**
     * Thực hiện trích khấu hao hàng tháng và sinh bút toán Sổ cái GL
     */
    public function runMonthlyDepreciation(int $companyId, string $month, ?string $description = null): DepreciationLog
    {
        return DB::transaction(function () use ($companyId, $month, $description) {
            $companyId = $this->requireCompanyId($companyId);
            // Serialize same-company month-end runs before checking for an
            // existing posted period. The legacy table has no unique
            // (company_id, month) constraint, so an exists() check alone is
            // not sufficient under concurrent workers.
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $this->periodMutationGuard->assertDepreciationMonth($companyId, $month);
            $this->assertFixedAssetPostingMappingsAvailable();
            // Kiểm tra trùng lặp kỳ
            $existing = DepreciationLog::where('company_id', $companyId)
                ->where('month', $month)
                ->where('is_posted', true)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new Exception("Kỳ khấu hao tháng {$month} đã được tính và ghi sổ.");
            }

            $preview = $this->previewMonthlyDepreciation($companyId, $month);

            if (empty($preview['lines']) || $preview['total_amount'] <= 0) {
                throw new Exception("Không có tài sản nào cần trích khấu hao trong tháng {$month}.");
            }

            $voucherDate = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();
            $voucherNumber = 'KHTS-'.$month;
            $desc = $description ?? "Trích khấu hao TSCĐ tháng {$month}";

            // 1. Tạo Master DepreciationLog
            $log = DepreciationLog::create([
                'company_id' => $companyId,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $voucherDate,
                'accounting_date' => $voucherDate,
                'month' => $month,
                'description' => $desc,
                'total_amount' => $preview['total_amount'],
                'is_posted' => true,
                'status' => 'posted',
                'created_by' => auth()->id(),
            ]);

            $glLines = [];

            // 2. Tạo Detail DepreciationLogLine và cập nhật FixedAsset
            foreach ($preview['lines'] as $lineData) {
                $asset = FixedAsset::where('company_id', $companyId)
                    ->findOrFail($lineData['fixed_asset_id']);
                $depreciationAmount = $lineData['monthly_depreciation'];

                DepreciationLogLine::create([
                    'depreciation_log_id' => $log->id,
                    'fixed_asset_id' => $asset->id,
                    'line_order' => $lineData['line_order'],
                    'asset_code' => $asset->asset_code,
                    'asset_name' => $asset->asset_name,
                    'department_code' => $asset->department_code,
                    'category_code' => $asset->category_code,
                    'original_cost' => $lineData['original_cost'],
                    'depreciable_cost' => $lineData['depreciable_cost'],
                    'useful_life_months' => $lineData['useful_life_months'],
                    'accumulated_depreciation_before' => $lineData['accumulated_depreciation_before'],
                    'monthly_depreciation' => $depreciationAmount,
                    'accumulated_depreciation_after' => $lineData['accumulated_depreciation_after'],
                    'net_value_after' => $lineData['net_value_after'],
                    'expense_account' => $lineData['expense_account'],
                    'depreciation_account' => $lineData['depreciation_account'],
                    'description' => "Khấu hao TSCĐ {$asset->asset_code} tháng {$month}",
                ]);

                // Cập nhật giá trị tài sản
                $asset->accumulated_depreciation += $depreciationAmount;
                $asset->net_value -= $depreciationAmount;
                if ($asset->net_value <= 0) {
                    $asset->net_value = 0;
                    $asset->status = 'fully_depreciated';
                }
                $asset->save();

                // Dòng Nợ (Debit) tài khoản chi phí theo bộ phận
                $glLines[] = [
                    'account_code' => $lineData['expense_account'],
                    'description' => "Khấu hao {$asset->asset_code} - {$asset->asset_name} T{$month}",
                    'debit_amount' => $depreciationAmount,
                    'credit_amount' => 0,
                ];

                // Dòng Có (Credit) tài khoản hao mòn lũy kế 2141
                $glLines[] = [
                    'account_code' => $lineData['depreciation_account'],
                    'description' => "Khấu hao {$asset->asset_code} - {$asset->asset_name} T{$month}",
                    'debit_amount' => 0,
                    'credit_amount' => $depreciationAmount,
                ];
            }

            // 3. Sinh bút toán GL Sổ cái
            $je = $this->journalEntryService->createPosted([
                'company_id' => $companyId,
                'voucher_type' => 'fixed_asset_depreciation',
                'voucher_number' => 'GL-KHTS-'.$month.'-'.time(),
                'voucher_date' => $voucherDate,
                'posting_date' => $voucherDate,
                'description' => $log->description,
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => DepreciationLog::class,
                'source_document_id' => $log->id,
                'lines' => $glLines,
            ]);

            $log->journal_entry_id = $je->id;
            $log->save();

            $this->recordAudit($log, 'depreciation.run', [], $log->getAttributes(), ['month' => $month, 'asset_count' => count($preview['lines'])]);

            return $log->load(['lines.fixedAsset', 'journalEntry']);
        });
    }

    /**
     * Bỏ ghi sổ / Hoàn nhập kỳ trích khấu hao
     */
    public function unpostMonthlyDepreciation(int $companyId, int $id): DepreciationLog
    {
        return DB::transaction(function () use ($companyId, $id) {
            $log = $this->depreciationLogForCompany($companyId, $id, ['lines'], true);
            $this->periodMutationGuard->assertExistingDocument($log, 'bỏ ghi sổ chứng từ khấu hao tài sản cố định');
            $before = $log->getAttributes();

            if (! $log->is_posted) {
                throw new Exception("Kỳ khấu hao {$log->month} chưa được ghi sổ.");
            }

            // Kiểm tra thứ tự tuần tự: không cho phép hủy tháng trước nếu tháng sau đã trích (cùng công ty)
            $subsequentPosted = DepreciationLog::where('company_id', $log->company_id)
                ->where('month', '>', $log->month)
                ->where('is_posted', true)
                ->exists();

            if ($subsequentPosted) {
                throw new Exception("Không thể hủy khấu hao tháng {$log->month} vì các tháng tiếp theo đã được trích khấu hao. Hãy hủy khấu hao từ tháng mới nhất trở về trước.");
            }

            // Hoàn nhập số liệu từng tài sản
            foreach ($log->lines as $line) {
                $asset = FixedAsset::where('company_id', $log->company_id)
                    ->find($line->fixed_asset_id);
                if ($asset) {
                    $asset->accumulated_depreciation = max(0, floatval($asset->accumulated_depreciation) - floatval($line->monthly_depreciation));
                    $asset->net_value = floatval($asset->original_cost) - floatval($asset->accumulated_depreciation);
                    if ($asset->net_value > 0 && $asset->status !== 'disposed') {
                        $asset->is_active = true;
                        $asset->status = 'posted';
                    }
                    $asset->save();
                }
            }

            // Hủy Journal Entry
            if ($log->journal_entry_id) {
                $this->journalEntryService->void($log->journal_entry_id, $companyId);
            }

            $log->is_posted = false;
            $log->status = 'draft';
            $log->save();

            $this->recordAudit($log, 'depreciation.unposted', $before, $log->getAttributes());

            return $log->load(['lines.fixedAsset', 'journalEntry']);
        });
    }

    /**
     * Xóa kỳ trích khấu hao
     */
    public function deleteMonthlyDepreciation(int $companyId, int $id): void
    {
        DB::transaction(function () use ($companyId, $id) {
            $log = $this->depreciationLogForCompany($companyId, $id, [], true);
            $this->periodMutationGuard->assertExistingDocument($log, 'xóa chứng từ khấu hao tài sản cố định');
            $before = $log->getAttributes();

            if ($log->is_posted) {
                $this->unpostMonthlyDepreciation($companyId, $id);
            }

            $log->lines()->delete();
            $log->delete();
            $this->recordAudit($log, 'depreciation.deleted', $before, []);
        });
    }

    /**
     * Thanh lý / Ghi giảm TSCĐ
     */
    public function disposeAsset(int $companyId, int $id, array $data): AssetDisposal
    {
        return DB::transaction(function () use ($companyId, $id, $data) {
            $asset = $this->assetForCompany($companyId, $id);
            $this->periodMutationGuard->assertNewEvent($asset, $data, 'lập chứng từ thanh lý tài sản cố định');
            $this->assertFixedAssetPostingMappingsAvailable();
            $assetBefore = $asset->getAttributes();

            if ($asset->status === 'disposed' || ! $asset->is_active) {
                throw new Exception("Tài sản {$asset->asset_code} đã được thanh lý hoặc không còn hoạt động.");
            }

            $voucherDate = $data['voucher_date'] ?? now()->toDateString();
            $disposalDate = $data['disposal_date'] ?? $voucherDate;
            $voucherNumber = $data['voucher_number'] ?? $this->generateNextCode($asset->company_id, 'GGTS');
            $disposalPrice = floatval($data['disposal_price'] ?? 0);
            $disposalReason = $data['disposal_reason'] ?? 'Thanh lý, ghi giảm TSCĐ';

            $originalCost = floatval($asset->original_cost);
            $accumulatedDepreciation = floatval($asset->accumulated_depreciation);
            $netValue = floatval($asset->net_value);

            // Đảm bảo nguyên giá = hao mòn + giá trị còn lại
            if (abs(($accumulatedDepreciation + $netValue) - $originalCost) > 0.01) {
                $netValue = max(0, $originalCost - $accumulatedDepreciation);
            }

            // 1. Tạo bản ghi AssetDisposal
            $disposal = AssetDisposal::create([
                'company_id' => $asset->company_id,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $voucherDate,
                'accounting_date' => $voucherDate,
                'disposal_date' => $disposalDate,
                'fixed_asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'asset_name' => $asset->asset_name,
                'department_code' => $asset->department_code,
                'disposal_type' => $data['disposal_type'] ?? 'liquidation',
                'disposal_reason' => $disposalReason,
                'original_cost' => $originalCost,
                'accumulated_depreciation' => $accumulatedDepreciation,
                'net_value' => $netValue,
                'disposal_price' => $disposalPrice,
                'tax_rate' => floatval($data['tax_rate'] ?? 0),
                'tax_amount' => floatval($data['tax_amount'] ?? 0),
                'total_income' => $disposalPrice + floatval($data['tax_amount'] ?? 0),
                'customer_id' => $data['customer_id'] ?? $data['buyer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? $data['buyer_name'] ?? null,
                'buyer_id' => $data['buyer_id'] ?? $data['customer_id'] ?? null,
                'buyer_name' => $data['buyer_name'] ?? $data['customer_name'] ?? null,
                'payment_method' => $data['payment_method'] ?? 'unpaid',
                'asset_account' => $asset->asset_account ?: '211',
                'depreciation_account' => $asset->depreciation_account ?: '2141',
                'expense_account' => '811',
                'income_account' => '711',
                'receivable_account' => match ($data['payment_method'] ?? 'unpaid') {
                    'cash' => '1111',
                    'bank' => '1121',
                    default => '131',
                },
                'status' => 'posted',
                'is_posted' => true,
                'created_by' => auth()->id(),
            ]);

            // 2. Cập nhật trạng thái FixedAsset
            $asset->status = 'disposed';
            $asset->is_active = false;
            $asset->disposal_date = $disposalDate;
            $asset->disposal_reason = $disposalReason;
            $asset->save();

            // 3. Sinh Bút toán Ghi giảm Sổ cái
            $glLines = [];

            // Nợ 2141 (Hao mòn lũy kế)
            if ($accumulatedDepreciation > 0) {
                $glLines[] = [
                    'account_code' => $asset->depreciation_account ?: '2141',
                    'description' => "Xóa sổ hao mòn lũy kế TSCĐ: {$asset->asset_name}",
                    'debit_amount' => $accumulatedDepreciation,
                    'credit_amount' => 0,
                ];
            }

            // Nợ 811 (Giá trị còn lại)
            if ($netValue > 0) {
                $glLines[] = [
                    'account_code' => '811',
                    'description' => "Giá trị còn lại TSCĐ thanh lý: {$asset->asset_name}",
                    'debit_amount' => $netValue,
                    'credit_amount' => 0,
                ];
            }

            // Có 211 (Nguyên giá)
            $glLines[] = [
                'account_code' => $asset->asset_account ?: '211',
                'description' => "Giảm nguyên giá TSCĐ: {$asset->asset_name}",
                'debit_amount' => 0,
                'credit_amount' => $originalCost,
            ];

            // Thu nhập thanh lý nếu có
            if ($disposalPrice > 0) {
                $paymentAccount = match ($data['payment_method'] ?? 'unpaid') {
                    'cash' => '1111',
                    'bank' => '1121',
                    default => '131',
                };

                $glLines[] = [
                    'account_code' => $paymentAccount,
                    'description' => "Thu tiền thanh lý TSCĐ: {$asset->asset_name}",
                    'debit_amount' => $disposalPrice,
                    'credit_amount' => 0,
                ];
                $glLines[] = [
                    'account_code' => '711',
                    'description' => "Thu nhập thanh lý TSCĐ: {$asset->asset_name}",
                    'debit_amount' => 0,
                    'credit_amount' => $disposalPrice,
                ];
            }

            $je = $this->journalEntryService->createPosted([
                'company_id' => $asset->company_id,
                'voucher_type' => 'fixed_asset_decrement',
                'voucher_number' => 'GL-GGTS-'.$voucherNumber,
                'voucher_date' => $voucherDate,
                'posting_date' => $voucherDate,
                'description' => "Ghi giảm/Thanh lý TSCĐ {$asset->asset_code} - {$asset->asset_name}",
                'total_amount' => 0,
                'status' => 'posted',
                'source_document_type' => AssetDisposal::class,
                'source_document_id' => $disposal->id,
                'lines' => $glLines,
            ]);

            $disposal->journal_entry_id = $je->id;
            $disposal->save();

            $this->recordAudit($asset, 'fixed_asset.disposed', $assetBefore, $asset->getAttributes(), ['asset_disposal_id' => $disposal->id]);
            $this->recordAudit($disposal, 'asset_disposal.created', [], $disposal->getAttributes(), ['fixed_asset_id' => $asset->id]);

            return $disposal->load(['fixedAsset', 'journalEntry']);
        });
    }

    /**
     * Đánh giá lại TSCĐ
     */
    public function revalueAsset(int $companyId, int $id, array $data): AssetRevaluation
    {
        return DB::transaction(function () use ($companyId, $id, $data) {
            $asset = $this->assetForCompany($companyId, $id);
            $this->periodMutationGuard->assertNewEvent($asset, $data, 'lập chứng từ đánh giá lại tài sản cố định');
            $this->assertFixedAssetPostingMappingsAvailable();
            $assetBefore = $asset->getAttributes();

            if ($asset->status === 'disposed' || ! $asset->is_active) {
                throw new Exception('Không thể đánh giá lại tài sản đã thanh lý.');
            }

            $newCost = floatval($data['new_original_cost']);
            $oldCost = floatval($asset->original_cost);
            $costDiff = $newCost - $oldCost;
            if (abs($costDiff) <= 0.01) {
                throw ValidationException::withMessages([
                    'new_original_cost' => 'Đánh giá lại phải tạo ra chênh lệch nguyên giá dương theo độ chính xác tiền tệ.',
                ]);
            }
            $voucherDate = $data['voucher_date'] ?? now()->toDateString();
            $voucherNumber = $data['voucher_number'] ?? $this->generateNextCode($asset->company_id, 'DGTS');
            $newUsefulLife = isset($data['new_useful_life']) ? intval($data['new_useful_life']) : (isset($data['new_useful_life_months']) ? intval($data['new_useful_life_months']) : $asset->useful_life_months);

            // 1. Tạo AssetRevaluation
            $reval = AssetRevaluation::create([
                'company_id' => $asset->company_id,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $voucherDate,
                'accounting_date' => $voucherDate,
                'fixed_asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'asset_name' => $asset->asset_name,
                'department_code' => $asset->department_code,
                'old_original_cost' => $oldCost,
                'new_original_cost' => $newCost,
                'cost_difference' => $costDiff,
                'old_accumulated_depreciation' => $asset->accumulated_depreciation,
                'new_accumulated_depreciation' => $asset->accumulated_depreciation,
                'depreciation_difference' => 0,
                'old_net_value' => $asset->net_value,
                'new_net_value' => $newCost - floatval($asset->accumulated_depreciation),
                'old_useful_life_months' => $asset->useful_life_months,
                'new_useful_life_months' => $newUsefulLife,
                'old_useful_life' => $asset->useful_life_months,
                'new_useful_life' => $newUsefulLife,
                'old_monthly_depreciation' => $asset->monthly_depreciation,
                'new_monthly_depreciation' => $newUsefulLife > 0 ? round($newCost / $newUsefulLife) : 0,
                'decision_number' => $data['decision_number'] ?? null,
                'decision_date' => $data['decision_date'] ?? null,
                'reason' => $data['reason'] ?? 'Đánh giá lại TSCĐ',
                'asset_account' => $asset->asset_account ?: '211',
                'revaluation_account' => '412',
                'depreciation_account' => $asset->depreciation_account ?: '2141',
                'status' => 'posted',
                'is_posted' => true,
                'created_by' => auth()->id(),
            ]);

            // 2. Cập nhật FixedAsset
            $asset->original_cost = $newCost;
            $asset->depreciable_cost = $newCost;
            $asset->useful_life_months = $newUsefulLife;
            $asset->net_value = max(0, $newCost - floatval($asset->accumulated_depreciation));
            if ($newUsefulLife > 0) {
                $asset->monthly_depreciation = round($newCost / $newUsefulLife);
            }
            $asset->save();

            // 3. Hạch toán Sổ cái qua TK 412
            if (abs($costDiff) > 0.01) {
                $glLines = [];
                if ($costDiff > 0) {
                    // Tăng nguyên giá: Nợ 211 / Có 412
                    $glLines[] = [
                        'account_code' => $asset->asset_account ?: '211',
                        'description' => "Đánh giá tăng nguyên giá TSCĐ {$asset->asset_name}",
                        'debit_amount' => $costDiff,
                        'credit_amount' => 0,
                    ];
                    $glLines[] = [
                        'account_code' => '412',
                        'description' => "Chênh lệch đánh giá lại TSCĐ {$asset->asset_name}",
                        'debit_amount' => 0,
                        'credit_amount' => $costDiff,
                    ];
                } else {
                    // Giảm nguyên giá: Nợ 412 / Có 211
                    $absDiff = abs($costDiff);
                    $glLines[] = [
                        'account_code' => '412',
                        'description' => "Chênh lệch đánh giá lại TSCĐ {$asset->asset_name}",
                        'debit_amount' => $absDiff,
                        'credit_amount' => 0,
                    ];
                    $glLines[] = [
                        'account_code' => $asset->asset_account ?: '211',
                        'description' => "Đánh giá giảm nguyên giá TSCĐ {$asset->asset_name}",
                        'debit_amount' => 0,
                        'credit_amount' => $absDiff,
                    ];
                }

                $je = $this->journalEntryService->createPosted([
                    'company_id' => $asset->company_id,
                    'voucher_type' => 'fixed_asset_revaluation',
                    'voucher_number' => 'GL-DGTS-'.$voucherNumber,
                    'voucher_date' => $voucherDate,
                    'posting_date' => $voucherDate,
                    'description' => "Đánh giá lại TSCĐ {$asset->asset_code} - {$asset->asset_name}",
                    'total_amount' => 0,
                    'status' => 'posted',
                    'source_document_type' => AssetRevaluation::class,
                    'source_document_id' => $reval->id,
                    'lines' => $glLines,
                ]);

                $reval->journal_entry_id = $je->id;
                $reval->save();
            }

            $this->recordAudit($asset, 'fixed_asset.revalued', $assetBefore, $asset->getAttributes(), ['asset_revaluation_id' => $reval->id]);
            $this->recordAudit($reval, 'asset_revaluation.created', [], $reval->getAttributes(), ['fixed_asset_id' => $asset->id]);

            return $reval->load(['fixedAsset', 'journalEntry']);
        });
    }

    /**
     * Lấy danh sách các kỳ trích khấu hao
     */
    public function getDepreciationPeriods(?int $companyId = null)
    {
        $companyId = $this->requireCompanyId($companyId);

        return DepreciationLog::where('company_id', $companyId)
            ->withCount('lines')
            ->orderByDesc('month')
            ->get();
    }

    /**
     * Lấy chi tiết kỳ trích khấu hao
     */
    public function getDepreciationPeriodById(int $companyId, int $id): DepreciationLog
    {
        return $this->depreciationLogForCompany($companyId, $id, ['lines.fixedAsset', 'journalEntry.lines']);
    }

    /**
     * Lấy danh sách chứng từ thanh lý
     */
    public function getDisposals(?int $companyId = null)
    {
        $companyId = $this->requireCompanyId($companyId);

        return AssetDisposal::where('company_id', $companyId)
            ->with(['fixedAsset', 'journalEntry'])
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Lấy danh sách chứng từ đánh giá lại
     */
    public function getRevaluations(?int $companyId = null)
    {
        $companyId = $this->requireCompanyId($companyId);

        return AssetRevaluation::where('company_id', $companyId)
            ->with(['fixedAsset', 'journalEntry'])
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Fixed-asset lifecycle posting still uses legacy account defaults in
     * increment, depreciation, disposal, and revaluation paths. Until an
     * owner-approved, effective-dated mapping resolver exists, production
     * must not create GL entries from those defaults.
     */
    private function assertFixedAssetPostingMappingsAvailable(): void
    {
        if (config('accounting.enforce_fixed_asset_posting_account_mappings', true)) {
            throw ValidationException::withMessages([
                'account_mappings' => 'Không thể ghi sổ TSCĐ khi chưa có mapping tài khoản được phê duyệt.',
            ]);
        }
    }

    private function requireCompanyId(?int $companyId): int
    {
        $actor = auth()->user();
        $actorCompanyId = $actor?->company_id;
        $resolvedCompanyId = $companyId ?? $actorCompanyId;

        if ($resolvedCompanyId === null || (int) $resolvedCompanyId <= 0 || ($actor !== null && (int) $actorCompanyId <= 0)) {
            throw ValidationException::withMessages([
                'company_id' => 'An authenticated company context is required.',
            ]);
        }
        if ($actor !== null && (int) $resolvedCompanyId !== (int) $actorCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return (int) $resolvedCompanyId;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @param array<string, mixed> $metadata */
    private function recordAudit(Model $model, string $action, array $before, array $after, array $metadata = []): void
    {
        $this->auditService->record($model, $action, $before, $after, null, array_merge([
            'domain' => 'fixed_assets',
            'operation' => $action,
        ], $metadata));
    }

    /** Resolve a lifecycle resource only inside the caller's explicit tenant. */
    private function assetForCompany(int $companyId, int $id): FixedAsset
    {
        $companyId = $this->requireCompanyId($companyId);

        return FixedAsset::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
    }

    /** Resolve a depreciation period only inside the caller's explicit tenant. */
    private function depreciationLogForCompany(int $companyId, int $id, array $with = [], bool $forUpdate = false): DepreciationLog
    {
        $companyId = $this->requireCompanyId($companyId);

        $query = DepreciationLog::where('company_id', $companyId)->with($with);
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }
}
