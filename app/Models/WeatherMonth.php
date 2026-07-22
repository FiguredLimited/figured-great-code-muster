<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeatherMonth extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'month' => 'date:Y-m-d',
        'rainfall_mm' => 'float',
        'rainfall_pct_normal' => 'float',
        'mean_temp_c' => 'float',
        'soil_moisture_deficit_mm' => 'float',
    ];
}
