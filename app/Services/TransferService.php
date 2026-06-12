<?php
declare(strict_types=1);
namespace App\Services;

use App\Jobs\RetryTransferJob;
use App\Jobs\SendTransferNotificationJob;
use App\Models\Wallet;
use App\Models\AuditLog;
use App\Models\LedgerTransaction;
use App\Services\LedgerService;
use App\DTOs\TransferMoneyDTO;
use InvalidArgumentException;
use LogicException;

class TransferService
{
    public function __construct(private LedgerService $ledgerService)
    {
    }

    /**
     * Attempt a transfer. If $autoRetry is true and the failure is transient,
     * a retry job will be queued and null will be returned to indicate async handling.
     *
     * @return LedgerTransaction|null
     * @throws InvalidArgumentException|LogicException
     */
    public function transfer(TransferMoneyDTO $dto, bool $autoRetry = false): ?LedgerTransaction
    {
        try {
            $from = Wallet::findOrFail($dto->fromWalletId);
            $to = Wallet::findOrFail($dto->toWalletId);

            $transaction = $this->ledgerService->transfer($from, $to, $dto->amount, $dto->description);

            // send notification asynchronously
            SendTransferNotificationJob::dispatch($from->user_id, $transaction->id, 'transfer_success');

            return $transaction;
        } catch (LogicException | InvalidArgumentException $e) {
            // business rejection - record audit and do not retry

            AuditLog::create([
                'user_id' => $dto->fromWalletId ?? null,
                'wallet_id' => $dto->fromWalletId,
                'transaction_id' => null,
                'action' => 'transfer_rejected',
                'amount' => -$dto->amount,
                'meta' => ['reason' => $e->getMessage()],
            ]);

            throw $e;
        } catch (\Exception $e) {
            // transient error
            if ($autoRetry) {
                RetryTransferJob::dispatch($dto->fromWalletId, $dto->toWalletId, $dto->amount, $dto->description, $dto->fromWalletId, 1);
                return null;
            }

            // if not auto-retry, bubble as server error
            throw $e;
        }
    }
}
