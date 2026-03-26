<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ProviderCircuitBreaker
{
    private string $namespace = 'speedcheck:circuit';
    private int $failureThreshold;
    private int $windowSeconds;
    private int $openSeconds;

    public function __construct()
    {
        $config = config('speedcheck.circuit_breaker', []);

        $this->failureThreshold = max(1, (int) ($config['failure_threshold'] ?? 3));
        $this->windowSeconds = max(5, (int) ($config['window_seconds'] ?? 300));
        $this->openSeconds = max(5, (int) ($config['open_seconds'] ?? 60));
    }

    public function isOpen(string $providerKey): bool
    {
        return (bool) Redis::get($this->openKey($providerKey));
    }

    public function remainingOpenSeconds(string $providerKey): int
    {
        $ttl = Redis::ttl($this->openKey($providerKey));

        return $ttl > 0 ? $ttl : 0;
    }

    public function recordFailure(string $providerKey): void
    {
        $failureKey = $this->failureKey($providerKey);
        $failures = Redis::incr($failureKey);
        Redis::expire($failureKey, $this->windowSeconds);

        if ($failures >= $this->failureThreshold) {
            Redis::setex($this->openKey($providerKey), $this->openSeconds, 1);
            Log::warning('SpeedCheck circuit geopend', [
                'provider' => $providerKey,
                'failures' => $failures,
                'window_seconds' => $this->windowSeconds,
                'open_seconds' => $this->openSeconds,
            ]);
        }
    }

    public function recordSuccess(string $providerKey): void
    {
        $failureKey = $this->failureKey($providerKey);
        $openKey = $this->openKey($providerKey);

        $hadFailures = (bool) Redis::get($failureKey);
        $wasOpen = (bool) Redis::get($openKey);

        Redis::del($failureKey);
        Redis::del($openKey);

        if ($wasOpen || $hadFailures) {
            Log::info('SpeedCheck circuit reset', [
                'provider' => $providerKey,
                'had_failures' => $hadFailures,
                'was_open' => $wasOpen,
            ]);
        }
    }

    public function currentFailures(string $providerKey): int
    {
        return (int) Redis::get($this->failureKey($providerKey));
    }

    private function failureKey(string $providerKey): string
    {
        $normalized = strtolower($providerKey);

        return "{$this->namespace}:{$normalized}:failures";
    }

    private function openKey(string $providerKey): string
    {
        $normalized = strtolower($providerKey);

        return "{$this->namespace}:{$normalized}:open";
    }
}

