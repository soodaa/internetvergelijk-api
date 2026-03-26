<?php

namespace App\Services;

use App\Http\Controllers\PostcodeController;
use App\Support\ProviderTokenStore;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Promise\Utils;

class HealthStatusService
{
    private PostcodeController $postcodeController;

    public function __construct(PostcodeController $postcodeController)
    {
        $this->postcodeController = $postcodeController;
    }

    public function evaluate(): array
    {
        $report = [
            'providers' => [],
            'failed_providers' => [],
            'tokens' => [],
            'token_warnings' => [],
        ];

        $providers = config('health.providers', []);
        $providerPromises = [];
        $addresses = [];
        $expectsMap = [];

        foreach ($providers as $providerKey => $config) {
            $addresses[$providerKey] = $this->makeAddress(
                $config['postcode'] ?? '',
                $config['number'] ?? '',
                $config['extension'] ?? null
            );
            $expectsMap[$providerKey] = $config['expects'] ?? [];
            $providerPromises[$providerKey] = $this->evaluateProviderAsync(
                $providerKey,
                $addresses[$providerKey],
                $expectsMap[$providerKey]
            );
        }

        if (!empty($providerPromises)) {
            $results = Utils::all($providerPromises)->wait();
            foreach ($results as $providerKey => $providerReport) {
                $report['providers'][$providerKey] = $providerReport;
                if ($providerReport['status'] !== 'ok') {
                    $report['failed_providers'][] = $providerKey;
                }
            }
        }

        $tokens = config('health.tokens', []);

        foreach ($tokens as $tokenKey => $tokenConfig) {
            $tokenReport = $this->evaluateToken($tokenKey, $tokenConfig);
            $report['tokens'][$tokenKey] = $tokenReport;

            if ($tokenReport['status'] !== 'ok') {
                $report['token_warnings'][] = $tokenReport['message'];
            }
        }

        return $report;
    }

    private function evaluateProvider(string $providerKey, array $config): array
    {
        $address = $this->makeAddress(
            $config['postcode'] ?? '',
            $config['number'] ?? '',
            $config['extension'] ?? null
        );

        $expects = $config['expects'] ?? [];
        $result = null;

        try {
            $result = $this->postcodeController->runConfiguredProvider($address, $providerKey, 0, false);
            return $this->summarizeProvider($providerKey, $address, $expects, $result, null);
        } catch (\Throwable $throwable) {
            return $this->summarizeProvider($providerKey, $address, $expects, null, $throwable);
        }
    }

    private function evaluateProviderAsync(string $providerKey, \stdClass $address, array $expects)
    {
        return $this->postcodeController->runConfiguredProviderAsync($address, $providerKey, 0)->then(
            fn ($result) => $this->summarizeProvider($providerKey, $address, $expects, $result, null),
            fn ($reason) => $this->summarizeProvider($providerKey, $address, $expects, null, $reason)
        );
    }

    private function evaluateToken(string $tokenKey, array $config): array
    {
        $label = $config['label'] ?? $tokenKey;
        $entry = ProviderTokenStore::get($tokenKey);

        if (!$entry || empty($entry['token'])) {
            return $this->logTokenWarning($tokenKey, $label, 'warning', "{$label} token ontbreekt", null);
        }

        $expiresAt = isset($entry['expires_at']) ? (int) $entry['expires_at'] : null;

        if ($expiresAt === null) {
            return $this->logTokenWarning($tokenKey, $label, 'warning', "{$label} token geen expires_at bekend", null);
        }

        $minutesLeft = floor(($expiresAt - now()->getTimestamp()) / 60);

        if ($minutesLeft <= 5) {
            return $this->logTokenWarning($tokenKey, $label, 'warning', "{$label} token verloopt binnen {$minutesLeft} minuten", $minutesLeft);
        }

        return [
            'key' => $tokenKey,
            'label' => $label,
            'status' => 'ok',
            'minutes_left' => $minutesLeft,
            'message' => "{$label} token ok ({$minutesLeft} min resterend)",
        ];
    }

    private function logProviderWarning(
        string $providerKey,
        \stdClass $address,
        string $status,
        array $messages,
        array $download,
        array $meta,
        array $expects
    ): void {
        Log::channel('healthcheck')->warning('Healthcheck provider issue', [
            'provider' => $providerKey,
            'status' => $status,
            'messages' => $messages,
            'address' => [
                'postcode' => $address->postcode,
                'number' => $address->number,
                'extension' => $address->extension,
            ],
            'download' => $download,
            'expects' => $expects,
            'meta' => $meta,
        ]);
    }

    private function logTokenWarning(string $tokenKey, string $label, string $status, string $message, ?int $minutesLeft): array
    {
        Log::channel('healthcheck')->warning('Healthcheck token warning', [
            'token' => $tokenKey,
            'label' => $label,
            'status' => $status,
            'minutes_left' => $minutesLeft,
            'message' => $message,
        ]);

        return [
                'key' => $tokenKey,
                'label' => $label,
            'status' => $status,
            'minutes_left' => $minutesLeft,
            'message' => $message,
            ];
    }

    private function makeAddress(string $postcode, $number, ?string $extension = null): \stdClass
    {
        $address = new \stdClass();
        $address->postcode = strtoupper(str_replace(' ', '', $postcode));
        $address->number = is_numeric($number) ? (int) $number : $number;
        $extension = $extension !== null ? trim($extension) : null;
        $address->extension = $extension === '' ? null : $extension;

        return $address;
    }

    private function summarizeProvider(
        string $providerKey,
        \stdClass $address,
        array $expects,
        ?array $result,
        $rejection
    ): array {
        $status = 'ok';
        $messages = [];
        $download = [];
        $meta = [];

        if ($rejection !== null) {
            $status = 'error';
            $messages[] = $this->stringifyReason($rejection);
            $this->logProviderWarning($providerKey, $address, $status, $messages, $download, $meta, $expects);

            return [
                'provider' => $providerKey,
                'address' => $address,
                'expects' => $expects,
                'download' => $download,
                'meta' => $meta,
                'status' => $status,
                'messages' => $messages,
                'result' => $result,
            ];
        }

        if (($result['status'] ?? '') === 'error') {
            $status = 'error';
            $messages[] = $result['message'] ?? 'Onbekende fout';
        }

        $payload = $result['data'] ?? [];
        $download = Arr::get($payload, '0.download', []);
        $meta = $result['meta'] ?? [];

        if (($meta['circuit']['state'] ?? null) === 'open') {
            $status = 'warning';
            $retryAfter = $meta['circuit']['retry_after_seconds'] ?? null;
            $messages[] = 'circuit open' . ($retryAfter ? " ({$retryAfter}s resterend)" : '');
        }

        $missing = [];
        foreach ($expects as $expect) {
            $value = $download[$expect] ?? null;
            if (!($value > 0)) {
                $missing[] = $expect;
            }
        }

        if (!empty($missing) && $status === 'ok') {
            $status = 'warning';
            foreach ($missing as $missingSpeed) {
                $messages[] = "missing expected {$missingSpeed} speed";
            }
        }

        if ($status !== 'ok') {
            $this->logProviderWarning($providerKey, $address, $status, $messages, $download, $meta, $expects);
        }

        return [
            'provider' => $providerKey,
            'address' => $address,
            'expects' => $expects,
            'download' => $download,
            'meta' => $meta,
            'status' => $status,
            'messages' => $messages,
            'result' => $result,
        ];
    }

    private function stringifyReason($reason): string
    {
        if ($reason instanceof \Throwable) {
            return $reason->getMessage();
        }

        if (is_scalar($reason)) {
            return (string) $reason;
        }

        return 'onbekende fout';
    }
}
