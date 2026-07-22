<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Farm extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function reportLines(): HasMany
    {
        return $this->hasMany(ReportLine::class);
    }

    public function commentaries(): HasMany
    {
        return $this->hasMany(ReportCommentary::class);
    }
}
