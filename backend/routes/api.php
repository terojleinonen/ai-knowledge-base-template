<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DocumentController;
use App\Support\ClientIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('config', fn () => [
    'demo' => (bool) config('knowledge.demo.enabled'),
    'registration' => (bool) config('knowledge.registration.enabled'),
    'limits' => config('knowledge.limits'),
]);

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::post('auth/guest', [AuthController::class, 'guest'])->middleware('throttle:guests');

// The caller's own IP as the app sees it: lets you verify proxy configuration after deploying.
Route::get('client-ip', fn (Request $request) => [
    'ip' => ClientIp::of($request),
    'request_ip' => $request->ip(),
    'cf_connecting_ip' => $request->header('CF-Connecting-IP'),
    // Temporary, to configure TRUSTED_PROXIES / KB_CLIENT_IP_HEADER for the hosting platform.
    'x_forwarded_for' => $request->header('X-Forwarded-For'),
    'true_client_ip' => $request->header('True-Client-IP'),
])->middleware('throttle:auth');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('documents', [DocumentController::class, 'index']);
    Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:uploads');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->whereNumber('document');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->whereNumber('document');
    Route::post('documents/{document}/reprocess', [DocumentController::class, 'reprocess'])
        ->whereNumber('document')->middleware('throttle:uploads');

    Route::get('conversations', [ConversationController::class, 'index']);
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation');
    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->whereNumber('conversation');

    Route::post('chat', [ConversationController::class, 'ask'])->middleware('throttle:chat');
    Route::post('chat/stream', [ConversationController::class, 'stream'])->middleware('throttle:chat');
});
