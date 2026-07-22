<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Email extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
        'replied_at' => 'datetime',
    ];
}
