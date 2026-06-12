<?php

namespace App\Services;

use App\Models\Wallet;

class ReconciliationService
{
    public function __construct(private LedgerService $ledgerService)
    {
    }

    /**
     * Reconcile all wallets and return an array of discrepancies.
     * Each item: ['wallet_id' => int, 'stored' => int, 'ledger' => int]
     */
    public function reconcileAll(): array
    {
        $discrepancies = [];

        foreach (Wallet::all() as $wallet) {
            $ledger = $this->ledgerService->balance($wallet);

            $stored = 0;
            $attrs = $wallet->getAttributes();
            if (array_key_exists('balance', $attrs)) {
                $stored = (int) $attrs['balance'];
            }

            if ($ledger !== $stored) {
                $discrepancies[] = [
                    'wallet_id' => $wallet->id,
                    'stored' => $stored,
                    'ledger' => $ledger,
                ];
            }
        }

        return $discrepancies;
    }
}
