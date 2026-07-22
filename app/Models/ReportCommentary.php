<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportCommentary extends Model
{
    protected $guarded = [];

    protected $casts = [
        'month' => 'date:Y-m-d',
    ];
}
