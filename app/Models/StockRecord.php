<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'recorded_on' => 'date:Y-m-d',
    ];
}
