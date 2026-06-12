<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Auth')]
class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/register',
        summary: 'Register a new user',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'email', type: 'string', format: 'email'),
            new OA\Property(property: 'password', type: 'string')
        ])
    )]
    #[OA\Response(response: 201, description: 'User registered')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user->load('wallet'),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    

   
    #[OA\Post(
        path: '/api/login',
        summary: 'Login user',
        tags: ['Auth']
    )]
    #[OA\Response(
        response: 200,
        description: 'Login successful'
    )]
    #[OA\Response(
        response: 401,
        description: 'Invalid credentials'
    )]

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user->load('wallet'),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    #[OA\Get(
        path: '/api/me',
        summary: 'Get current user',
        tags: ['Auth']
    )]
    #[OA\Response(response: 200, description: 'Current user info')]
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->load('wallet'),
        ]);
    }

    #[OA\Post(
        path: '/api/logout',
        summary: 'Logout user',
        tags: ['Auth']
    )]
    #[OA\Response(response: 200, description: 'Logged out')]
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
