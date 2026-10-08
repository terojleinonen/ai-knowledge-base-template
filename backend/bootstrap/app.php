<?php

use App\Services\Chat\AnswerQuestion;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\RateLimitedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a reverse proxy, trust its X-Forwarded-* headers so $request->ip() is the
        // visitor's address. List the proxies' addresses or CIDR ranges explicitly: Laravel's "*"
        // trusts *every* address, so the visitor's own (forgeable) X-Forwarded-For entry wins.
        if ($proxies = env('TRUSTED_PROXIES')) {
            if (in_array(trim($proxies), ['*', '**'], true)) {
                throw new InvalidArgumentException('TRUSTED_PROXIES="*" would let visitors forge their IP via X-Forwarded-For. List proxy addresses or CIDR ranges, or set KB_CLIENT_IP_HEADER.');
            }

            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // AI provider quota (rate limit / no credit): a clear, temporary 503 instead of a 500.
        $exceptions->render(fn (RateLimitedException|InsufficientCreditsException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => AnswerQuestion::USAGE_LIMIT_MESSAGE], 503, ['Retry-After' => '3600'])
            : null);
    })->create();
