<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransferRequest;
use App\Models\IdempotencyKey;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(private LedgerService $ledgerService)
    {
    }

    public function transfer(TransferRequest $request): JsonResponse
    {
        $fromWallet = $request->user()->wallet;
        $toWallet = Wallet::findOrFail($request->recipient_wallet_id);

        if ($fromWallet->id === $toWallet->id) {
            return response()->json([
                'message' => 'Recipient wallet must differ from the sender wallet.',
            ], 422);
        }

        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return response()->json(['message' => 'Idempotency-Key header required.'], 400);
        }

        $user = $request->user();
        $payloadHash = sha1(json_encode(['route' => 'wallet.transfer', 'to' => $toWallet->id, 'amount' => $request->amount, 'description' => $request->description]));

        $response = DB::transaction(function () use ($user, $key, $payloadHash, $fromWallet, $toWallet, $request) {
            $record = IdempotencyKey::where('user_id', $user->id)->where('key', $key)->lockForUpdate()->first();

            if (! $record) {
                $record = IdempotencyKey::create([
                    'user_id' => $user->id,
                    'key' => $key,
                    'request_hash' => $payloadHash,
                ]);
            }

            if ($record->request_hash && $record->request_hash !== $payloadHash) {
                return response()->json(['message' => 'Idempotency key reuse with different payload.'], 409);
            }

            if ($record->response_code) {
                return response(json_decode($record->response_body, true), $record->response_code);
            }

            $record->request_hash = $payloadHash;
            $record->save();

            $transaction = $this->ledgerService->transfer(
                $fromWallet,
                $toWallet,
                $request->amount,
                $request->description
            );

            $body = ['transaction' => $transaction, 'wallet' => $fromWallet->fresh()];

            $record->response_code = 200;
            $record->response_body = json_encode($body);
            $record->save();

            return response()->json($body, 200);
        });

        return $response;
    }

    public function reverse(LedgerTransaction $transaction): JsonResponse
    {
        $userWalletId = request()->user()->wallet->id;

        if (! $transaction->entries->contains('wallet_id', $userWalletId)) {
            return response()->json(['message' => 'Unauthorized to reverse this transaction.'], 403);
        }

        $key = request()->header('Idempotency-Key');
        if (! $key) {
            return response()->json(['message' => 'Idempotency-Key header required.'], 400);
        }

        $user = request()->user();
        $payloadHash = sha1(json_encode(['route' => 'wallet.reverse', 'transaction_id' => $transaction->id]));

        $response = DB::transaction(function () use ($user, $key, $payloadHash, $transaction) {
            $record = IdempotencyKey::where('user_id', $user->id)->where('key', $key)->lockForUpdate()->first();

            if (! $record) {
                $record = IdempotencyKey::create([
                    'user_id' => $user->id,
                    'key' => $key,
                    'request_hash' => $payloadHash,
                ]);
            }

            if ($record->request_hash && $record->request_hash !== $payloadHash) {
                return response()->json(['message' => 'Idempotency key reuse with different payload.'], 409);
            }

            if ($record->response_code) {
                return response(json_decode($record->response_body, true), $record->response_code);
            }

            $record->request_hash = $payloadHash;
            $record->save();

            $reversal = $this->ledgerService->reverseTransaction($transaction);

            $body = ['transaction' => $reversal];

            $record->response_code = 200;
            $record->response_body = json_encode($body);
            $record->save();

            return response()->json($body, 200);
        });

        return $response;
    }
}
