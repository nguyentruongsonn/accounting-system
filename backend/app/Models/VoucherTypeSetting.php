<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VoucherTypeSetting extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id',
        'voucher_type',
        'name',
        'debit_account',
        'credit_account',
        'filter_debit',
        'filter_credit',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Danh sách Loại chứng từ hỗ trợ (theo chuẩn MISA AMIS)
     */
    public static array $VOUCHER_TYPES = [
        'thu_tien_mat' => 'Thu tiền mặt',
        'thu_tien_gui' => 'Thu tiền gửi',
        'chi_tien_mat' => 'Chi tiền mặt',
        'chi_tien_gui' => 'Chi tiền gửi',
        'chung_tu_khac' => 'Chứng từ nghiệp vụ khác',
    ];

    /**
     * Mặc định hệ thống theo category (chuẩn theo thứ tự MISA AMIS)
     */
    public static array $SYSTEM_DEFAULTS = [
        'thu_tien_mat' => [
            ['name' => '1. Thu tiền khách hàng (không theo hóa đơn)', 'debit_account' => '1111', 'credit_account' => '131', 'filter_debit' => '111', 'filter_credit' => '131, 511, 711'],
            ['name' => '2. Thu hoàn ứng nhân viên',                   'debit_account' => '1111', 'credit_account' => '141', 'filter_debit' => '111', 'filter_credit' => '141'],
            ['name' => '3. Rút tiền gửi về nhập quỹ',                 'debit_account' => '1111', 'credit_account' => '1121', 'filter_debit' => '111', 'filter_credit' => '112'],
            ['name' => '4. Thu hồi các khoản cho vay',                'debit_account' => '1111', 'credit_account' => '1288', 'filter_debit' => '111', 'filter_credit' => '128'],
            ['name' => '5. Thu khác',                                  'debit_account' => '1111', 'credit_account' => '711', 'filter_debit' => '111', 'filter_credit' => '711'],
        ],
        'chi_tien_mat' => [
            ['name' => '1. Trả tiền cho nhà cung cấp (không theo hóa đơn)', 'debit_account' => '331',  'credit_account' => '1111', 'filter_debit' => '331', 'filter_credit' => '111'],
            ['name' => '2. Tạm ứng cho nhân viên',                         'debit_account' => '141',  'credit_account' => '1111', 'filter_debit' => '141', 'filter_credit' => '111'],
            ['name' => '3. Chi mua ngoài có hóa đơn',                      'debit_account' => '1561', 'credit_account' => '1111', 'filter_debit' => '156', 'filter_credit' => '111'],
            ['name' => '4. Trả lương tạm ứng cho nhân viên',              'debit_account' => '3341', 'credit_account' => '1111', 'filter_debit' => '334', 'filter_credit' => '111'],
            ['name' => '5. Trả lương cho nhân viên',                       'debit_account' => '3341', 'credit_account' => '1111', 'filter_debit' => '334', 'filter_credit' => '111'],
            ['name' => '6. Gửi tiền vào ngân hàng',                        'debit_account' => '1121', 'credit_account' => '1111', 'filter_debit' => '112', 'filter_credit' => '111'],
            ['name' => '7. Chi cho vay',                                   'debit_account' => '1288', 'credit_account' => '1111', 'filter_debit' => '128', 'filter_credit' => '111'],
            ['name' => '8. Chi khác',                                      'debit_account' => '6428', 'credit_account' => '1111', 'filter_debit' => '642', 'filter_credit' => '111'],
            ['name' => '9. Nộp thuế TNDN tạm tính',                        'debit_account' => '3334', 'credit_account' => '1111', 'filter_debit' => '333', 'filter_credit' => '111'],
        ],
        'thu_tien_gui' => [
            ['name' => '1. Thu tiền gửi từ khách hàng',                   'debit_account' => '1121', 'credit_account' => '131', 'filter_debit' => '112', 'filter_credit' => '131'],
            ['name' => '2. Thu hoàn ứng qua tài khoản',                   'debit_account' => '1121', 'credit_account' => '141', 'filter_debit' => '112', 'filter_credit' => '141'],
            ['name' => '3. Thu lãi tiền gửi',                              'debit_account' => '1121', 'credit_account' => '515', 'filter_debit' => '112', 'filter_credit' => '515'],
            ['name' => '4. Thu hồi cho vay qua tài khoản',                'debit_account' => '1121', 'credit_account' => '1288', 'filter_debit' => '112', 'filter_credit' => '128'],
            ['name' => '5. Thu khác bằng tiền gửi',                       'debit_account' => '1121', 'credit_account' => '711', 'filter_debit' => '112', 'filter_credit' => '711'],
        ],
        'chi_tien_gui' => [
            ['name' => '1. Trả tiền NCC qua chuyển khoản',                 'debit_account' => '331',  'credit_account' => '1121', 'filter_debit' => '331', 'filter_credit' => '112'],
            ['name' => '2. Tạm ứng cho NV qua chuyển khoản',               'debit_account' => '141',  'credit_account' => '1121', 'filter_debit' => '141', 'filter_credit' => '112'],
            ['name' => '3. Chi trả lương qua tài khoản',                   'debit_account' => '3341', 'credit_account' => '1121', 'filter_debit' => '334', 'filter_credit' => '112'],
            ['name' => '4. Nộp thuế qua tài khoản ngân hàng',              'debit_account' => '333',  'credit_account' => '1121', 'filter_debit' => '333', 'filter_credit' => '112'],
            ['name' => '5. Nộp bảo hiểm qua tài khoản',                     'debit_account' => '338',  'credit_account' => '1121', 'filter_debit' => '338', 'filter_credit' => '112'],
            ['name' => '6. Chi phí ngân hàng',                            'debit_account' => '642',  'credit_account' => '1121', 'filter_debit' => '642', 'filter_credit' => '112'],
            ['name' => '7. Chi khác bằng tiền gửi',                        'debit_account' => '6428', 'credit_account' => '1121', 'filter_debit' => '642', 'filter_credit' => '112'],
        ],
        'chung_tu_khac' => [
            ['name' => '1. Khấu trừ thuế GTGT đầu vào',                   'debit_account' => '33311', 'credit_account' => '1331', 'filter_debit' => '333', 'filter_credit' => '133'],
            ['name' => '2. Kết chuyển doanh thu bán hàng',                'debit_account' => '511',  'credit_account' => '911',  'filter_debit' => '511', 'filter_credit' => '911'],
            ['name' => '3. Kết chuyển giá vốn hàng bán',                  'debit_account' => '911',  'credit_account' => '632',  'filter_debit' => '911', 'filter_credit' => '632'],
            ['name' => '4. Kết chuyển chi phí quản lý',                   'debit_account' => '911',  'credit_account' => '642',  'filter_debit' => '911', 'filter_credit' => '642'],
            ['name' => '5. Kết chuyển lãi lỗ',                             'debit_account' => '911',  'credit_account' => '4212', 'filter_debit' => '911', 'filter_credit' => '421'],
        ],
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByVoucherType($query, string $voucherType)
    {
        return $query->where('voucher_type', $voucherType);
    }
}
