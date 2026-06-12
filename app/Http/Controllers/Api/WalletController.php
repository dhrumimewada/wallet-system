<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\WithdrawRequest;
use App\Models\IdempotencyKey;
use App\Models\Wallet;
use App\Services\LedgerService;
use OpenApi\Attributes as OA;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use LogicException;

#[OA\Tag(name: 'Wallet')]
class WalletController extends Controller
{
    public function __construct(private LedgerService $ledgerService)
    {
    }

    #[OA\Get(
        path: '/api/wallet',
        summary: 'Get wallet for current user',
        tags: ['Wallet']
    )]
    #[OA\Response(response: 200, description: 'Wallet info')]
    public function show(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet;

        if (! $wallet) {
            $wallet = Wallet::create(['user_id' => $request->user()->id]);
        }

        return response()->json(['wallet' => $wallet->fresh()]);
    }

    #[OA\Post(
        path: '/api/wallet/deposit',
        summary: 'Deposit funds into wallet',
        tags: ['Wallet']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'amount', type: 'integer'),
            new OA\Property(property: 'description', type: 'string')
        ])
    )]
    #[OA\Response(response: 200, description: 'Deposit successful')]
    #[OA\Response(response: 400, description: 'Bad request')]
    #[OA\Response(response: 409, description: 'Idempotency conflict')]
    public function deposit(DepositRequest $request): JsonResponse
    {
        $wallet = $request->user()->wallet;

        if (! $wallet) {
            $wallet = Wallet::create(['user_id' => $request->user()->id]);
        }

        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return response()->json(['message' => 'Idempotency-Key header required.'], 400);
        }

        $user = $request->user();
        $payloadHash = sha1(json_encode(['route' => 'wallet.deposit', 'amount' => $request->amount, 'description' => $request->description]));

        $response = DB::transaction(function () use ($user, $key, $payloadHash, $wallet, $request) {
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

            $transaction = $this->ledgerService->deposit($wallet, (int) $request->amount, $request->description);

            $body = ['wallet' => $wallet->fresh(), 'transaction' => $transaction];

            $record->response_code = 200;
            $record->response_body = json_encode($body);
            $record->save();

            return response()->json($body, 200);
        });

        return $response;
    }

    #[OA\Post(
        path: '/api/wallet/withdraw',
        summary: 'Withdraw funds from wallet',
        tags: ['Wallet']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'amount', type: 'integer'),
            new OA\Property(property: 'description', type: 'string')
        ])
    )]
    #[OA\Response(response: 200, description: 'Withdrawal successful')]
    #[OA\Response(response: 400, description: 'Bad request')]
    #[OA\Response(response: 422, description: 'Insufficient funds or validation error')]
    public function withdraw(WithdrawRequest $request): JsonResponse
    {
        $wallet = $request->user()->wallet;

        if (! $wallet) {
            $wallet = Wallet::create(['user_id' => $request->user()->id]);
        }

        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return response()->json(['message' => 'Idempotency-Key header required.'], 400);
        }

        $user = $request->user();
        $payloadHash = sha1(json_encode(['route' => 'wallet.withdraw', 'amount' => $request->amount, 'description' => $request->description]));

        $response = DB::transaction(function () use ($user, $key, $payloadHash, $wallet, $request) {
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
                $transaction = $this->ledgerService->withdraw($wallet, (int) $request->amount, $request->description);
            } catch (LogicException $exception) {
                // store failure response
                $record->response_code = 422;
                $record->response_body = json_encode(['message' => $exception->getMessage()]);
                $record->save();

                return response()->json(['message' => $exception->getMessage()], 422);
            }

            $body = ['wallet' => $wallet->fresh(), 'transaction' => $transaction];

            $record->response_code = 200;
            $record->response_body = json_encode($body);
            $record->save();

            return response()->json($body, 200);
        });

        return $response;
    }

    #[OA\Get(
        path: '/api/wallet/statement',
        summary: 'Get wallet statement',
        tags: ['Wallet']
    )]
    #[OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string'), required: false)]
    #[OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string'), required: false)]
    #[OA\Response(response: 200, description: 'Statement returned')]
    public function statement(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet;

        if (! $wallet) {
            $wallet = Wallet::create(['user_id' => $request->user()->id]);
        }

        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : null;

        // opening balance = sum of entries before 'from'
        $opening = 0;
        if ($from) {
            $opening = LedgerEntry::query()
                ->where('wallet_id', $wallet->id)
                ->where('created_at', '<', $from)
                ->selectRaw("SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE -amount END) AS balance")
                ->value('balance');
        }

        $entriesQuery = LedgerEntry::query()->where('wallet_id', $wallet->id);
        if ($from) {
            $entriesQuery->where('created_at', '>=', $from);
        }
        if ($to) {
            $entriesQuery->where('created_at', '<=', $to);
        }

        $entries = $entriesQuery->orderBy('created_at')->get()->map(function ($e) {
            return [
                'id' => $e->id,
                'transaction_id' => $e->transaction_id,
                'entry_type' => $e->entry_type,
                'amount' => $e->amount,
                'created_at' => $e->created_at,
            ];
        })->toArray();

        $running = (int) $opening;
        $detailed = [];
        foreach ($entries as $e) {
            $delta = $e['entry_type'] === 'credit' ? $e['amount'] : -$e['amount'];
            $running += $delta;
            $e['running_balance'] = $running;
            $detailed[] = $e;
        }

        return response()->json([
            'wallet_id' => $wallet->id,
            'opening_balance' => (int) $opening,
            'entries' => $detailed,
            'closing_balance' => $running,
        ]);
    }
}
