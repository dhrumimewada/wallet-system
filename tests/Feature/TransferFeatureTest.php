<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;
use App\Jobs\RetryTransferJob;
use App\Services\LedgerService;

class TransferFeatureTest extends TestCase
{
    public function test_insufficient_funds_rejected(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create();

        $fromWallet = Wallet::create(['user_id' => $user->id]);
        $toWallet = Wallet::create(['user_id' => $recipient->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/wallet/transfer', [
            'recipient_wallet_id' => $toWallet->id,
            'amount' => 100,
            'description' => 'test',
        ], ['Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Insufficient funds.']);
    }

    public function test_idempotency_prevents_duplicate_transfers(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create();

        $fromWallet = Wallet::create(['user_id' => $user->id]);
        $toWallet = Wallet::create(['user_id' => $recipient->id]);

        // seed funds via LedgerService
        $this->app->make(LedgerService::class)->deposit($fromWallet, 1000, 'seed');

        $idempotencyKey = Str::uuid()->toString();

        $payload = [
            'recipient_wallet_id' => $toWallet->id,
            'amount' => 100,
            'description' => 'payment',
        ];

        $r1 = $this->actingAs($user, 'sanctum')->postJson('/api/wallet/transfer', $payload, ['Idempotency-Key' => $idempotencyKey]);
        $r1->assertStatus(200);

        $r2 = $this->actingAs($user, 'sanctum')->postJson('/api/wallet/transfer', $payload, ['Idempotency-Key' => $idempotencyKey]);
        $r2->assertStatus(200);
        $this->assertEquals($r1->json(), $r2->json());
    }

    public function test_auto_retry_schedules_job_on_transient_error(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create();

        $fromWallet = Wallet::create(['user_id' => $user->id]);
        $toWallet = Wallet::create(['user_id' => $recipient->id]);

        // seed funds
        $this->app->make(LedgerService::class)->deposit($fromWallet, 1000, 'seed');

        // mock LedgerService to throw generic exception to simulate transient failure
        $this->instance(LedgerService::class, new class extends LedgerService {
            public function __construct() {}
            public function transfer(\App\Models\Wallet $from, \App\Models\Wallet $to, int $amount, ?string $description = null): \App\Models\LedgerTransaction
            {
                throw new \Exception('transient');
            }
        });

        Queue::fake();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/wallet/transfer', [
            'recipient_wallet_id' => $toWallet->id,
            'amount' => 100,
            'description' => 'retry-test',
            'auto_retry' => true,
        ], ['Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(202);

        Queue::assertPushed(RetryTransferJob::class);
    }
}
