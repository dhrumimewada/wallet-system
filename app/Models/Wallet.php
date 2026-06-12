<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
    ];

    protected $appends = [
        'balance',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function getBalanceAttribute(): int
    {
        $balance = $this->entries()
            ->selectRaw(
                "SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE -amount END) AS balance"
            )
            ->value('balance');

        return (int) $balance;
    }

    public function reconcileBalance(): int
    {
        return $this->balance;
    }
}