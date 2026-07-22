<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockClass extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
