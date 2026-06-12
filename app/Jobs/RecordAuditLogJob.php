<?php
declare(strict_types=1);
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\LedgerTransaction;
use App\Models\AuditLog;

class RecordAuditLogJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private string $transactionId)
    {
    }

    public function handle(): void
    {
        $transaction = LedgerTransaction::with('entries.wallet')->find($this->transactionId);
        if (! $transaction) {
            return;
        }

        foreach ($transaction->entries as $entry) {
            AuditLog::create([
                'user_id' => $entry->wallet?->user_id ?? null,
                'wallet_id' => $entry->wallet_id,
                'transaction_id' => $transaction->id,
                'action' => $transaction->type,
                'amount' => $entry->entry_type === 'credit' ? $entry->amount : -$entry->amount,
                'meta' => ['entry_type' => $entry->entry_type],
            ]);
        }
    }
}
