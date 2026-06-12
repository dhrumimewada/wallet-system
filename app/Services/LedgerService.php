<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class LedgerService
{
    public function balance(Wallet $wallet): int
    {
        $balance = LedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->selectRaw(
                "SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE -amount END) AS balance"
            )
            ->value('balance');

        return (int) $balance;
    }

    public function deposit(Wallet $wallet, int $amount, ?string $description = null): LedgerTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Deposit amount must be positive.');
        }
        return DB::transaction(function () use ($wallet, $amount, $description) {
            // lock wallet row to prevent concurrent updates
            $locked = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            $transaction = $this->createTransaction('deposit', TransactionStatus::Posted, $description);

            $this->addEntries($transaction, [
                ['wallet_id' => $locked->id, 'entry_type' => 'credit', 'amount' => $amount],
                ['wallet_id' => null, 'entry_type' => 'debit', 'amount' => $amount],
            ]);

            return $this->finalizeTransaction($transaction, TransactionStatus::Posted);
        });
    }

    public function withdraw(Wallet $wallet, int $amount, ?string $description = null): LedgerTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Withdrawal amount must be positive.');
        }
        return DB::transaction(function () use ($wallet, $amount, $description) {
            // lock wallet and re-check balance under lock
            $locked = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            if ($this->balance($locked) < $amount) {
                throw new LogicException('Insufficient funds.');
            }

            $transaction = $this->createTransaction('withdrawal', TransactionStatus::Posted, $description);

            $this->addEntries($transaction, [
                ['wallet_id' => $locked->id, 'entry_type' => 'debit', 'amount' => $amount],
                ['wallet_id' => null, 'entry_type' => 'credit', 'amount' => $amount],
            ]);

            return $this->finalizeTransaction($transaction, TransactionStatus::Posted);
        });
    }

    public function transfer(Wallet $from, Wallet $to, int $amount, ?string $description = null): LedgerTransaction
    {
        if ($from->id === $to->id) {
            throw new InvalidArgumentException('Source and destination wallets must differ.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Transfer amount must be positive.');
        }

        if ($this->balance($from) < $amount) {
            throw new LogicException('Insufficient funds.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $description) {
            // lock both wallets in a consistent order to avoid deadlocks
            $ids = [$from->id, $to->id];
            sort($ids, SORT_NUMERIC);
            $lockedWallets = Wallet::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            $lockedFrom = $lockedWallets[$from->id];
            $lockedTo = $lockedWallets[$to->id];

            if ($this->balance($lockedFrom) < $amount) {
                throw new LogicException('Insufficient funds.');
            }

            $transaction = $this->createTransaction('transfer', TransactionStatus::Pending, $description);

            $this->addEntries($transaction, [
                ['wallet_id' => $lockedFrom->id, 'entry_type' => 'debit', 'amount' => $amount],
                ['wallet_id' => $lockedTo->id, 'entry_type' => 'credit', 'amount' => $amount],
            ]);

            return $this->finalizeTransaction($transaction, TransactionStatus::Posted);
        });
    }

    public function reverseTransaction(LedgerTransaction $transaction, ?string $description = null): LedgerTransaction
    {
        if ($transaction->status === TransactionStatus::Reversed) {
            throw new LogicException('Transaction is already reversed.');
        }

        return DB::transaction(function () use ($transaction, $description) {
            $reversal = $this->createTransaction(
                'reversal',
                TransactionStatus::Pending,
                $description ?? sprintf('Reversal of %s', $transaction->id),
                $transaction->id
            );

            $reversalEntries = $transaction->entries->map(fn (LedgerEntry $entry) => [
                'wallet_id' => $entry->wallet_id,
                'entry_type' => $entry->entry_type === 'debit' ? 'credit' : 'debit',
                'amount' => $entry->amount,
            ])->toArray();

            $this->addEntries($reversal, $reversalEntries);
            $finalized = $this->finalizeTransaction($reversal, TransactionStatus::Reversed);

            $transaction->status = TransactionStatus::Reversed;
            $transaction->save();

            return $finalized;
        });
    }

    public function createTransaction(string $type, TransactionStatus $status, ?string $description = null, ?string $reversalOf = null): LedgerTransaction
    {
        return LedgerTransaction::create([
            'type' => $type,
            'status' => $status,
            'description' => $description,
            'reversal_of' => $reversalOf,
        ]);
    }

    public function addEntries(LedgerTransaction $transaction, array $entries): void
    {
        if (empty($entries)) {
            throw new InvalidArgumentException('A ledger transaction requires at least one entry.');
        }

        $this->assertBalanced($entries);

        foreach ($entries as $entry) {
            LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'wallet_id' => $entry['wallet_id'],
                'entry_type' => $entry['entry_type'],
                'amount' => $entry['amount'],
            ]);
        }
    }

    public function finalizeTransaction(LedgerTransaction $transaction, TransactionStatus $status): LedgerTransaction
    {
        if ($transaction->entries()->count() === 0) {
            throw new LogicException('Transaction entries must be recorded before finalizing.');
        }

        if (! $this->isBalanced($transaction)) {
            throw new LogicException('Ledger transaction is not balanced.');
        }

        $transaction->status = $status;
        $transaction->save();

        // record audit logs for each entry
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

        return $transaction;
    }

    private function isBalanced(LedgerTransaction $transaction): bool
    {
        $net = $transaction->entries()->selectRaw(
            "SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE -amount END) AS balance"
        )->value('balance');

        return (int) $net === 0;
    }

    private function assertBalanced(array $entries): void
    {
        $net = array_reduce(
            $entries,
            static fn (int $carry, array $entry) =>
                $carry + ($entry['entry_type'] === 'credit' ? $entry['amount'] : -$entry['amount']),
            0
        );

        if ($net !== 0) {
            throw new InvalidArgumentException('Ledger entries must balance to zero.');
        }
    }
}
