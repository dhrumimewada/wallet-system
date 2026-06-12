<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:reconcile-wallets')]
#[Description('Reconcile ledger balances against cached wallet balances')]
class ReconcileWallets extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $service = app(\App\Services\ReconciliationService::class);

        $discrepancies = $service->reconcileAll();

        if (empty($discrepancies)) {
            $this->info('All wallets reconcile.');
            return 0;
        }

        $this->error('Found ' . count($discrepancies) . ' discrepancies:');

        foreach ($discrepancies as $d) {
            $this->line(sprintf('Wallet %s: stored=%s ledger=%s', $d['wallet_id'], $d['stored'], $d['ledger']));
        }

        return 1;
    }
}
