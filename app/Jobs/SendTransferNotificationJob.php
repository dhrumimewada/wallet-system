<?php
declare(strict_types=1);
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\User;
use App\Models\AuditLog;

class SendTransferNotificationJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private ?int $userId, private ?string $transactionId = null, private ?string $message = null)
    {
    }

    public function handle(): void
    {
        if (! $this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        // Minimal notification: record an audit log entry indicating a notification was sent.
        AuditLog::create([
            'user_id' => $user->id,
            'wallet_id' => $user->wallet?->id ?? null,
            'transaction_id' => $this->transactionId,
            'action' => 'notification',
            'amount' => 0,
            'meta' => ['message' => $this->message],
        ]);
    }
}
