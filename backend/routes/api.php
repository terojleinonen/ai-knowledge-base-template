<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('config', fn () => [
    'demo' => (bool) config('knowledge.demo.enabled'),
    'registration' => (bool) config('knowledge.registration.enabled'),
    'limits' => config('knowledge.limits'),
]);

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('guest', [AuthController::class, 'guest']);
});

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
