<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'invoice_date' => 'date:Y-m-d',
        'total' => 'float',
        'entered_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
