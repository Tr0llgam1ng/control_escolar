<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Contract: Auth Service v1
 *  POST /api/auth/login     {email, password}  -> {token, token_type, expires_at, user}
 *  POST /api/auth/validate  {token}            -> {valid, user}
 *  POST /api/auth/logout    {token}            -> {revoked}
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', mb_strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales incorrectas.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'La cuenta está desactivada.'], 403);
        }

        [$plain, $token] = AccessToken::issueFor($user);

        return response()->json([
            'token' => $plain,
            'token_type' => 'Bearer',
            'expires_at' => $token->expires_at->toIso8601String(),
            'user' => new UserResource($user),
        ]);
    }

    public function validateToken(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $token = AccessToken::findValid($data['token']);

        if (! $token || ! $token->user->is_active) {
            return response()->json(['valid' => false, 'message' => 'Token inválido o expirado.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return response()->json(['valid' => true, 'user' => new UserResource($token->user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $deleted = AccessToken::where('token_hash', hash('sha256', $data['token']))->delete();

        return response()->json(['revoked' => $deleted > 0]);
    }
}
