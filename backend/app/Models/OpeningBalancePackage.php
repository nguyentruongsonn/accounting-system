<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

final class OpeningBalancePackage extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['effective_date' => 'date', 'confirmed_at' => 'datetime'];

    public function accountLines()
    {
        return $this->hasMany(OpeningBalanceAccountLine::class, 'package_id');
    }

    public function partyLines()
    {
        return $this->hasMany(OpeningBalancePartyLine::class, 'package_id');
    }

    public function inventoryLines()
    {
        return $this->hasMany(OpeningBalanceInventoryLine::class, 'package_id');
    }
}
