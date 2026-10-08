<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The visitor's IP address, for per-visitor limits.
 *
 * Behind a proxy (Render, Cloudflare...) the connecting address is the proxy's, so every
 * visitor would share one limit. Configure either TRUSTED_PROXIES (Laravel then reads
 * X-Forwarded-For) or KB_CLIENT_IP_HEADER (e.g. CF-Connecting-IP, set by the edge). Only
 * use a header the edge overwrites; otherwise visitors could forge it.
 */
final class ClientIp
{
    public static function of(Request $request): string
    {
        $header = config('knowledge.client_ip_header');

        if ($header) {
            $value = trim((string) $request->header($header));

            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }

        return (string) $request->ip();
    }
}
