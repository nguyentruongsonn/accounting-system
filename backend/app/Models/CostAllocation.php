<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\HasVoucherReferences;
use Illuminate\Database\Eloquent\Model;

class CostAllocation extends Model
{
    use BelongsToCompany, HasVoucherReferences;

    protected $table = 'cost_allocations';

    protected $fillable = [
        'company_id',
        'production_order_id',
        'month',
        'direct_material_cost',
        'direct_labor_cost',
        'manufacturing_overhead',
        'wip_beginning',
        'wip_ending',
        'total_cost',
        'is_posted',
        'journal_entry_id',
    ];

    protected $casts = [
        'direct_material_cost' => 'integer',
        'direct_labor_cost' => 'integer',
        'manufacturing_overhead' => 'integer',
        'wip_beginning' => 'integer',
        'wip_ending' => 'integer',
        'total_cost' => 'integer',
        'is_posted' => 'boolean',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
