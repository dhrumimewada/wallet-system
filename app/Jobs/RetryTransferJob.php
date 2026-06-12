<?php
declare(strict_types=1);
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\TransferService;
use App\DTOs\TransferMoneyDTO;
use App\Models\Wallet;
use App\Models\AuditLog;
use App\Jobs\SendTransferNotificationJob;

class RetryTransferJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        private int $fromWalletId,
        private int $toWalletId,
        private int $amount,
        private ?string $description,
        private ?int $userId,
        private int $attempt = 1,
        private int $maxAttempts = 3
    ) {
    }

    public function handle(): void
    {
        $from = Wallet::find($this->fromWalletId);
        $to = Wallet::find($this->toWalletId);
        if (! $from || ! $to) {
            return;
        }

        $transferService = app(TransferService::class);

        try {
            $dto = new TransferMoneyDTO($this->fromWalletId, $this->toWalletId, $this->amount, $this->description);
            $transaction = $transferService->transfer($dto, false);
            if ($transaction) {
                // success: notify user
                SendTransferNotificationJob::dispatch($this->userId, $transaction->id, 'retry_success');
            }
        } catch (\LogicException $e) {
            // business rejection, record and notify
            AuditLog::create([
                'user_id' => $this->userId,
                'wallet_id' => $from->id,
                'transaction_id' => null,
                'action' => 'transfer_rejected',
                'amount' => -$this->amount,
                'meta' => ['reason' => $e->getMessage()],
            ]);
            SendTransferNotificationJob::dispatch($this->userId, null, 'transfer_rejected');
        } catch (\Exception $e) {
            // transient failure
            if ($this->attempt < $this->maxAttempts) {
                $delaySeconds = [10, 30, 60][$this->attempt - 1] ?? 60;
                RetryTransferJob::dispatch($this->fromWalletId, $this->toWalletId, $this->amount, $this->description, $this->userId, $this->attempt + 1, $this->maxAttempts)->delay(now()->addSeconds($delaySeconds));
            } else {
                // permanent failure: record and notify
                AuditLog::create([
                    'user_id' => $this->userId,
                    'wallet_id' => $from->id,
                    'transaction_id' => null,
                    'action' => 'transfer_failed',
                    'amount' => -$this->amount,
                    'meta' => ['error' => $e->getMessage()],
                ]);
                SendTransferNotificationJob::dispatch($this->userId, null, 'transfer_failed');
            }
        }
    }
}
