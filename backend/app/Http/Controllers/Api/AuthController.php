<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Demo\DemoAccounts;
use App\Services\Turnstile;
use App\Services\UsageLimits;
use App\Support\ClientIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {

        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        return $this->tokenResponse($user, $request->input('device_name', 'web'), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', strtolower($request->string('email')))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return $this->tokenResponse($user, $request->input('device_name', 'web'));
    }

    public function guest(Request $request, DemoAccounts $demo, UsageLimits $limits, Turnstile $turnstile): JsonResponse
    {
        abort_unless(config('knowledge.demo.enabled'), 404);

        if ($turnstile->enabled() && ! $this->isMonitor($request)
            && ! $turnstile->verify($request->input('turnstile_token'), ClientIp::of($request))) {
            throw ValidationException::withMessages([
                'turnstile_token' => 'Please complete the human check and try again.',
            ]);
        }

        $limits->consumeGuest();

        return $this->tokenResponse($demo->createGuest(), 'demo', 201);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Automated checks (bin/check-demo) identify themselves with a shared secret instead of Turnstile.
     */
    private function isMonitor(Request $request): bool
    {
        $expected = config('knowledge.turnstile.monitor_token');
        $given = $request->header('X-Monitor-Token');

        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }

    private function tokenResponse(User $user, string $deviceName, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => new UserResource($user),
        ], $status);
    }
}
