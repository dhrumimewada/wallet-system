<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'user_id',
        'key',
        'request_hash',
        'response_code',
        'response_body',
    ];

    protected $casts = [
        'response_code' => 'integer',
    ];
}
