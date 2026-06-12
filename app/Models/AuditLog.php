<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_id',
        'transaction_id',
        'action',
        'amount',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
