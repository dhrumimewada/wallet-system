<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LedgerTransaction extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'type',
        'status',
        'description',
        'reversal_of',
    ];

    protected $casts = [
        'status' => TransactionStatus::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            if (! $transaction->getKey()) {
                $transaction->{$transaction->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of');
    }
}
