<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'month' => 'date:Y-m-d',
        'budget' => 'float',
        'actual' => 'float',
    ];
}
