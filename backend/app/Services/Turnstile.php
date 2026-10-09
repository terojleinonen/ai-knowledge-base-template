<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cloudflare Turnstile verification (bot protection for starting demo sessions).
 *
 * See https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 */
class Turnstile
{
    public const ACTION = 'guest';

    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function enabled(): bool
    {
        return filled(config('knowledge.turnstile.secret_key'));
    }

    /**
     * Whether the token is valid for our action. If Cloudflare can't be reached, the
     * request is allowed (and logged): the IP limits and daily caps still apply, and a
     * Cloudflare outage shouldn't take the demo down.
     */
    public function verify(?string $token, ?string $ip): bool
    {
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $result = Http::asForm()
                ->timeout(5)
                // 2 attempts in total: one retry on network or server errors.
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()))
                ->post(self::ENDPOINT, array_filter([
                    'secret' => config('knowledge.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                    'idempotency_key' => (string) Str::uuid(), // safe retries
                ]))
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $e) {
            Log::warning('Turnstile verification unavailable; allowing the request', ['error' => $e->getMessage()]);

            return true;
        }

        if (! ($result['success'] ?? false)) {
            return false;
        }

        // Tokens from Cloudflare's dummy test keys carry no action.
        $action = $result['action'] ?? null;

        return $action === null || $action === '' || $action === self::ACTION;
    }
}
