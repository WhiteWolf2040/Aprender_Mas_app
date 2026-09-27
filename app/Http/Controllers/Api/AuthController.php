<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:16'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'avatar' => ['nullable', 'string', 'max:10'],
            'password' => ['required', 'string', 'min:4'],
            'account_type' => ['nullable', 'in:parent'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'avatar' => $data['avatar'] ?? '🦊',
            'password' => $data['password'],
            'role' => 'padre',
            'plan_key' => 'free',
            'energy' => 3,
            'energy_reset_at' => now()->addDay(),
        ]);
        $user = $user->fresh();
        $token = $user->createToken('aprendermas-web')->plainTextToken;

        return response()->json(['user' => $this->userPayload($user), 'token' => $token], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::whereIn('role', ['padre', 'maestro'])
            ->where('email', $data['name'])
            ->first();
        if (!$user || !$user->password || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'credentials' => ['Las credenciales no son correctas.'],
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('aprendermas-web')->plainTextToken;

        return response()->json(['user' => $this->userPayload($user), 'token' => $token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    private function userPayload(User $user): array
    {
        return [
            ...$user->only([
            'id', 'name', 'email', 'role', 'avatar', 'total_stars', 'streak', 'level',
            'equipped_sticker', 'equipped_costume',
            'plan_key', 'energy', 'energy_reset_at',
            'subscription_status', 'subscription_ends_at',
            ]),
            'has_premium' => $user->hasActivePlan(),
        ];
    }
}
