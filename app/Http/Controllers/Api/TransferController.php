<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransferRequest;
use App\Models\IdempotencyKey;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Services\LedgerService;
use App\Services\TransferService;
use App\DTOs\TransferMoneyDTO;
use LogicException;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Transfer')]
class TransferController extends Controller
{
    public function __construct(private LedgerService $ledgerService, private TransferService $transferService)
    {
    }

    #[OA\Post(
        path: '/api/wallet/transfer',
        summary: 'Transfer funds to another wallet',
        tags: ['Transfer']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'recipient_wallet_id', type: 'integer'),
            new OA\Property(property: 'amount', type: 'integer'),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'auto_retry', type: 'boolean')
        ])
    )]
    #[OA\Response(response: 200, description: 'Transfer completed')]
    #[OA\Response(response: 202, description: 'Transfer scheduled for retry')]
    #[OA\Response(response: 400, description: 'Bad request')]
    #[OA\Response(response: 409, description: 'Idempotency conflict')]
    #[OA\Response(response: 422, description: 'Business rejection')]
    #[OA\Response(response: 500, description: 'Server error')]
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


            try {
                $dto = new TransferMoneyDTO(
                    $fromWallet->id,
                    $toWallet->id,
                    (int) $request->amount,
                    $request->description
                );

                $transaction = $this->transferService->transfer(
                    $dto,
                    (bool) ($request->input('auto_retry') ?? false)
                );

                if ($transaction === null) {
                    $body = ['message' => 'Transfer scheduled for retry.'];
                    $status = 202;
                } else {
                    $body = ['transaction' => $transaction, 'wallet' => $fromWallet->fresh()];
                    $status = 200;
                }
            } catch (LogicException | InvalidArgumentException $e) {
                $record->response_code = 422;
                $record->response_body = json_encode(['message' => $e->getMessage()]);
                $record->save();

                return response()->json(['message' => $e->getMessage()], 422);
            } catch (\Exception $e) {
                $record->response_code = 500;
                $record->response_body = json_encode(['message' => 'Transfer failed.']);
                $record->save();

                return response()->json(['message' => 'Transfer failed.'], 500);
            }

            $record->response_code = $status;
            $record->response_body = json_encode($body);
            $record->save();

            return response()->json($body, $status);
        });

        return $response;
    }

    #[OA\Post(
        path: '/api/wallet/transactions/{transaction}/reverse',
        summary: 'Reverse a ledger transaction',
        tags: ['Transfer']
    )]
    #[OA\Parameter(name: 'transaction', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Reversal completed')]
    #[OA\Response(response: 403, description: 'Unauthorized')]
    #[OA\Response(response: 400, description: 'Bad request')]
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
