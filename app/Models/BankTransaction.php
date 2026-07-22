<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'transacted_on' => 'date:Y-m-d',
        'amount' => 'float',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
