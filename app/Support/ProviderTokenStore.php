<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProviderTokenStore
{
    private const CACHE_PREFIX = 'speedcheck:token:';

    /**
     * @return array|null {token: string, expires_at: int, meta?: array}
     */
    public static function get(string $provider): ?array
    {
        $entry = Cache::get(self::key($provider));

        return is_array($entry) ? $entry : null;
    }

    public static function getValidToken(string $provider, int $leewaySeconds = 60): ?string
    {
        $entry = self::get($provider);

        if (!$entry || empty($entry['token'])) {
            return null;
        }

        $expiresAt = isset($entry['expires_at']) ? (int) $entry['expires_at'] : null;

        if ($expiresAt !== null) {
            $now = Carbon::now()->getTimestamp();
            if ($now + $leewaySeconds >= $expiresAt) {
                return null;
            }
        }

        return $entry['token'];
    }

    public static function put(string $provider, string $token, int $expiresInSeconds, array $meta = []): void
    {
        $bufferedSeconds = max(60, $expiresInSeconds - 60);
        $expiresAt = Carbon::now()->addSeconds($bufferedSeconds);

        $payload = array_merge($meta, [
            'token' => $token,
            'expires_at' => $expiresAt->getTimestamp(),
        ]);

        Cache::put(self::key($provider), $payload, $expiresAt);

        Log::debug('ProviderTokenStore saved token', [
            'provider' => $provider,
            'expires_in' => $bufferedSeconds,
        ]);
    }

    public static function forget(string $provider): void
    {
        Cache::forget(self::key($provider));
    }

    private static function key(string $provider): string
    {
        return self::CACHE_PREFIX . strtolower($provider);
    }
}

