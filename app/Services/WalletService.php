<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\LedgerTransaction;

class WalletService
{
    public function __construct(private LedgerService $ledgerService)
    {
    }

    public function deposit(User $user, int $amount, ?string $description = null): LedgerTransaction
    {
        return $this->ledgerService->deposit($user->wallet, $amount, $description);
    }

    public function withdraw(User $user, int $amount, ?string $description = null): LedgerTransaction
    {
        return $this->ledgerService->withdraw($user->wallet, $amount, $description);
    }

    public function transfer(Wallet $from, Wallet $to, int $amount, ?string $description = null): LedgerTransaction
    {
        return $this->ledgerService->transfer($from, $to, $amount, $description);
    }
}
