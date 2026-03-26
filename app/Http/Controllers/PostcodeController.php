<?php

namespace App\Http\Controllers;

use App\Support\ProviderLog;
use App\Support\ProviderCircuitBreaker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\VarDumper\VarDumper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Throwable;

class PostcodeController extends Controller
{
    private ProviderCircuitBreaker $circuitBreaker;

    public function __construct(ProviderCircuitBreaker $circuitBreaker)
    {
        $this->circuitBreaker = $circuitBreaker;
    }

    public function speedCheck(Request $request)
    {
        return $this->getAllProvidersV2($request);
    }

    public function getAllProvidersV2(Request $request)
    {
        $previousDumpHandler = null;

        if (!app()->runningInConsole()) {
            $previousDumpHandler = VarDumper::setHandler(static function (): void {
                // Swallow dump() output during API responses to keep JSON clean
            });

            register_shutdown_function(static function (?callable $handler): void {
                VarDumper::setHandler($handler);
            }, $previousDumpHandler);
        }

        $address = $this->normalizeSpeedcheckAddress($request);

        if ($address === null) {
            return response()->json([
                'error' => 1,
                'error_message' => 'Postcode en huisnummer zijn verplicht',
            ], 422);
        }

        // Optionele cache-bypass voor front-end: ?fresh=1
        $fresh = (bool) $request->boolean('fresh', false);

        $providers = config('providers', []);
        if (empty($providers)) {
            return response()->json([
                'error' => 1,
                'error_message' => 'Geen providers geconfigureerd voor speedCheck v2',
            ], 500);
        }

        $verbose = (int) $request->boolean('verbose', false);
        $output = [];
        $errors = [];
        $startTimes = [];
        $promises = [];
        $concurrency = max(1, (int) config('speedcheck.concurrency', 4));

        foreach ($providers as $providerKey => $providerConfig) {
            // Inactieve providers: return null values zodat front-end alle providers ziet
            if (empty($providerConfig['active'])) {
                $output[] = [
                    'provider' => $providerConfig['name'] ?? $providerKey,
                    'download' => [
                        'dsl' => null,
                        'glasvezel' => null,
                        'kabel' => null,
                    ],
                ];
                continue;
            }

            $cacheKey = $this->buildSpeedcheckCacheKey($providerKey, $address);
            // Alleen cache lezen wanneer fresh=false
            if (!$fresh) {
                $cached = Cache::get($cacheKey);
                if ($cached !== null && is_array($cached)) {
                    $status = $cached['status'] ?? 'unknown';
                    $durationMs = (int) ($cached['meta']['duration_ms'] ?? 0);

                    if ($status === 'error') {
                        $errors[$providerConfig['name']] = $cached['message'] ?? 'Onbekende fout';
                    } elseif (!empty($cached['data'])) {
                        $output = array_merge($output, $cached['data']);
                    }

                    Log::info('SpeedCheck provider afgerond (cache)', ProviderLog::context(
                        $providerConfig['name'] ?? $providerKey,
                        $address,
                        [
                            'status' => $status,
                            'duration_ms' => $durationMs,
                            'from_cache' => true,
                        ]
                    ));

                    continue;
                }
            }

            $startTimes[$providerKey] = microtime(true);

            $promises[$providerKey] = $this->dispatchProviderAsync($address, $providerKey, $providerConfig, $verbose)->then(
                function (array $result) use ($providerKey, $providerConfig, $cacheKey, &$startTimes, $address) {
                    $durationMs = (int) round((microtime(true) - ($startTimes[$providerKey] ?? microtime(true))) * 1000);
                    $status = (string) ($result['status'] ?? 'error');

                    $result['meta'] = array_merge($result['meta'] ?? [], [
                        'duration_ms' => $durationMs,
                        'from_cache' => false,
                    ]);

                    $ttl = $this->resolveSpeedcheckCacheTtl($providerConfig, $status);
                    Cache::put($cacheKey, $result, $ttl);

                    $circuitMeta = $result['meta']['circuit']['state'] ?? null;

                    if ($circuitMeta === 'open') {
                        // Circuit is al actief, niet opnieuw tellen.
                    } elseif ($status === 'error') {
                        $this->circuitBreaker->recordFailure($providerKey);
                    } else {
                        $this->circuitBreaker->recordSuccess($providerKey);
                    }

                    $result['meta']['circuit'] = array_merge(
                        $result['meta']['circuit'] ?? [],
                        $this->buildCircuitMeta($providerKey)
                    );

                    Log::info('SpeedCheck provider afgerond', ProviderLog::context(
                        $providerConfig['name'] ?? $providerKey,
                        $address,
                        [
                            'status' => $status,
                            'duration_ms' => $durationMs,
                            'from_cache' => false,
                        ]
                    ));

                    return [
                        'providerKey' => $providerKey,
                        'config' => $providerConfig,
                        'result' => $result,
                    ];
                },
                function ($reason) use ($providerKey, $providerConfig, $cacheKey, &$startTimes, $address) {
                    $durationMs = (int) round((microtime(true) - ($startTimes[$providerKey] ?? microtime(true))) * 1000);
                    $message = $reason instanceof Throwable ? $reason->getMessage() : 'Onbekende fout';

                    $result = [
                        'status' => 'error',
                        'message' => $message,
                        'data' => [],
                        'meta' => [
                            'duration_ms' => $durationMs,
                            'from_cache' => false,
                        ],
                    ];

                    $ttl = $this->resolveSpeedcheckCacheTtl($providerConfig, 'error');
                    Cache::put($cacheKey, $result, $ttl);
                    $this->circuitBreaker->recordFailure($providerKey);

                    $result['meta']['circuit'] = array_merge(
                        $result['meta']['circuit'] ?? [],
                        $this->buildCircuitMeta($providerKey)
                    );

                    Log::error('SpeedCheck provider fout', ProviderLog::context(
                        $providerConfig['name'] ?? $providerKey,
                        $address,
                        [
                            'error' => $message,
                            'duration_ms' => $durationMs,
                        ]
                    ));

                    return [
                        'providerKey' => $providerKey,
                        'config' => $providerConfig,
                        'result' => $result,
                    ];
                }
            );
        }

        if (!empty($promises)) {
            // Verwerk promises in batches met concurrency limiet
            $promiseArray = array_values($promises);
            $chunks = array_chunk($promiseArray, $concurrency);

            foreach ($chunks as $chunk) {
                $settled = Utils::settle($chunk)->wait();

                foreach ($settled as $key => $result) {
                    if ($result['state'] === 'fulfilled') {
                        $payload = $result['value'];
                        $providerConfig = $payload['config'];
                        $providerResult = $payload['result'];

                        if (($providerResult['status'] ?? null) === 'error') {
                            $errors[$providerConfig['name']] = $providerResult['message'] ?? 'Onbekende fout';
                            continue;
                        }

                        if (!empty($providerResult['data'])) {
                            $output = array_merge($output, $providerResult['data']);
                        }
                    } else {
                        $reason = $result['reason'] ?? null;
                        $message = $reason instanceof Throwable ? $reason->getMessage() : 'Onbekende fout';
                        $errors['unknown'][] = $message;
                        Log::error('SpeedCheck promise rejected', ['error' => $message]);
                    }
                }
            }
        }

        if (empty($output) && !empty($errors)) {
            return response()->json([
                'error' => 1,
                'error_message' => 'Geen providers beschikbaar door fouten',
                'details' => $errors,
            ], 502);
        }

        if (empty($output)) {
            return response()->json([
                'error' => 1,
                'error_message' => 'Geen resultaten voor deze postcode',
            ], 404);
        }

        if (!empty($errors)) {
            Log::warning('SpeedCheck v2 gedeeltelijke providerfouten', [
                'postcode' => $address->postcode,
                'number' => $address->number,
                'extension' => $address->extension,
                'errors' => $errors,
            ]);
        }

        return response()->json($output, 200);
    }

    public function debugHgvtRaw(Request $request)
    {
        $address = $this->normalizeSpeedcheckAddress($request);

        if ($address === null) {
            return response()->json([
                'error' => 1,
                'error_message' => 'Postcode en huisnummer zijn verplicht',
            ], 422);
        }

        $params = [
            'version' => 4,
            'zipcode' => $address->postcode,
            'housenumber' => $address->number,
        ];

        if (!empty($address->extension)) {
            $params['housenumberext'] = $address->extension;
        }

        $baseUrl = rtrim((string) $request->input('base_url', 'https://b2b.trikx.nl/availability/'), '/');
        $url = $baseUrl . '?' . http_build_query($params);

        $client = new Client([
            'http_errors' => false,
            'timeout' => 10,
            'connect_timeout' => 6,
        ]);

        $startTime = microtime(true);

        try {
            $response = $client->get($url);
        } catch (Throwable $throwable) {
            Log::error('HGVT raw debug call failed', ProviderLog::context('HGVT-RAW', $address, [
                'error' => $throwable->getMessage(),
            ]));

            return response()->json([
                'error' => 1,
                'error_message' => 'HGVT debug call failed: ' . $throwable->getMessage(),
            ], 502);
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 0;
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw);
        $decodedError = (is_object($decoded) && isset($decoded->response->error)) ? $decoded->response->error : null;

        Log::info('HGVT raw debug response', ProviderLog::context('HGVT-RAW', $address, [
            'status' => $status,
            'duration_ms' => $durationMs,
            'error' => $decodedError,
        ]));

        return response()->json([
            'status' => $status,
            'duration_ms' => $durationMs,
            'address' => [
                'postcode' => $address->postcode,
                'number' => $address->number,
                'extension' => $address->extension,
            ],
            'url' => $url,
            'raw' => $raw,
            'decoded' => $decoded,
        ], 200);
    }

    public function runConfiguredProvider(\stdClass $address, string $providerKey, int $verbose = 0, bool $useCache = true): array
    {
        $providers = config('providers', []);

        if (!isset($providers[$providerKey])) {
            throw new \InvalidArgumentException("Onbekende provider sleutel '{$providerKey}' in config/providers.php");
        }

        $providerConfig = $providers[$providerKey];

        if (empty($providerConfig['active'])) {
            throw new \RuntimeException("Provider '{$providerConfig['name']}' is gedeactiveerd in config/providers.php");
        }

        $cacheKey = $this->buildSpeedcheckCacheKey($providerKey, $address);

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null && is_array($cached)) {
                $cached['meta'] = array_merge($cached['meta'] ?? [], ['from_cache' => true]);
                return $cached;
            }
        }

        $startTime = microtime(true);
        $promise = $this->dispatchProviderAsync($address, $providerKey, $providerConfig, $verbose);

        try {
            $result = $promise->wait();
        } catch (Throwable $throwable) {
            Log::error('SpeedCheck runConfiguredProvider fout', [
                'provider' => $providerConfig['name'] ?? $providerKey,
                'error' => $throwable->getMessage(),
            ]);

            $result = [
                'status' => 'error',
                'message' => $throwable->getMessage(),
                'data' => [],
            ];

            $this->circuitBreaker->recordFailure($providerKey);
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $result['meta'] = array_merge($result['meta'] ?? [], [
            'duration_ms' => $durationMs,
            'from_cache' => false,
        ]);

        $status = (string) ($result['status'] ?? 'error');
        $circuitMeta = $result['meta']['circuit']['state'] ?? null;

        if ($circuitMeta !== 'open') {
            if ($status === 'error') {
                $this->circuitBreaker->recordFailure($providerKey);
            } else {
                $this->circuitBreaker->recordSuccess($providerKey);
            }
        }
        $ttl = $this->resolveSpeedcheckCacheTtl($providerConfig, $status);

        if ($useCache) {
            Cache::put($cacheKey, $result, $ttl);
        }

        $result['meta']['circuit'] = array_merge(
            $result['meta']['circuit'] ?? [],
            $this->buildCircuitMeta($providerKey)
        );

        return $result;
    }

    public function runConfiguredProviderAsync(\stdClass $address, string $providerKey, int $verbose = 0): PromiseInterface
    {
        $providers = config('providers', []);

        if (!isset($providers[$providerKey])) {
            return Create::rejectionFor(new \InvalidArgumentException("Onbekende provider sleutel '{$providerKey}' in config/providers.php"));
        }

        $providerConfig = $providers[$providerKey];

        if (empty($providerConfig['active'])) {
            return Create::rejectionFor(new \RuntimeException("Provider '{$providerConfig['name']}' is gedeactiveerd in config/providers.php"));
        }

        $startTime = microtime(true);

        return $this->dispatchProviderAsync($address, $providerKey, $providerConfig, $verbose)->then(
            function (array $result) use ($providerKey, $providerConfig, $address, $startTime) {
                $durationMs = (int) round((microtime(true) - $startTime) * 1000);
                $status = (string) ($result['status'] ?? 'error');
                $result['meta'] = array_merge($result['meta'] ?? [], [
                    'duration_ms' => $durationMs,
                    'from_cache' => false,
                ]);

                $circuitMeta = $result['meta']['circuit']['state'] ?? null;

                if ($circuitMeta !== 'open') {
                    if ($status === 'error') {
                        $this->circuitBreaker->recordFailure($providerKey);
                    } else {
                        $this->circuitBreaker->recordSuccess($providerKey);
                    }
                }

                $result['meta']['circuit'] = array_merge(
                    $result['meta']['circuit'] ?? [],
                    $this->buildCircuitMeta($providerKey)
                );

                Log::info('SpeedCheck health async provider afgerond', ProviderLog::context(
                    $providerConfig['name'] ?? $providerKey,
                    $address,
                    [
                        'status' => $status,
                        'duration_ms' => $durationMs,
                    ]
                ));

                return $result;
            },
            function ($reason) use ($providerKey, $providerConfig, $address, $startTime) {
                $durationMs = (int) round((microtime(true) - $startTime) * 1000);
                $message = $reason instanceof Throwable ? $reason->getMessage() : 'Onbekende fout';

                $result = [
                    'status' => 'error',
                    'message' => $message,
                    'data' => [],
                    'meta' => [
                        'duration_ms' => $durationMs,
                        'from_cache' => false,
                    ],
                ];

                $this->circuitBreaker->recordFailure($providerKey);
                $result['meta']['circuit'] = array_merge(
                    $result['meta']['circuit'] ?? [],
                    $this->buildCircuitMeta($providerKey)
                );

                Log::error('SpeedCheck health async provider fout', ProviderLog::context(
                    $providerConfig['name'] ?? $providerKey,
                    $address,
                    [
                        'error' => $message,
                        'duration_ms' => $durationMs,
                    ]
                ));

                return $result;
            }
        );
    }

    private function normalizeSpeedcheckAddress(Request $request): ?\stdClass
    {
        $postcodeInput = strtoupper(str_replace(' ', '', (string) $request->input('postcode', '')));
        $houseNumber = $request->input('nr');

        if ($postcodeInput === '' || $houseNumber === null || $houseNumber === '') {
            return null;
        }

        $extension = $request->input('nr_add');
        $extension = $extension !== null ? trim((string) $extension) : null;
        $extension = $extension === '' ? null : $extension;

        $address = new \stdClass();
        $address->postcode = $postcodeInput;
        $address->number = is_numeric($houseNumber) ? (int) $houseNumber : trim((string) $houseNumber);
        $address->extension = $extension;

        return $address;
    }

    private function buildSpeedcheckCacheKey(string $providerKey, \stdClass $address): string
    {
        $extension = $address->extension ?? 'null';

        return sprintf(
            'speedcheck:%s:%s:%s:%s',
            $providerKey,
            $address->postcode,
            $address->number,
            $extension
        );
    }

    private function dispatchProviderAsync(\stdClass $address, string $providerKey, array $providerConfig, int $verbose): PromiseInterface
    {
        if ($this->circuitBreaker->isOpen($providerKey)) {
            $retryAfter = $this->circuitBreaker->remainingOpenSeconds($providerKey);

            Log::warning('SpeedCheck circuit open - provider overgeslagen', [
                'provider' => $providerConfig['name'] ?? $providerKey,
                'retry_after_seconds' => $retryAfter,
            ]);

            return Create::promiseFor([
                'status' => 'error',
                'message' => 'Circuit breaker actief voor deze provider. Probeer later opnieuw.',
                'data' => [],
                'meta' => [
                    'circuit' => [
                        'state' => 'open',
                        'retry_after_seconds' => $retryAfter,
                    ],
                ],
            ]);
        }

        $maxRetries = max(0, (int) config('speedcheck.retry.attempts', 0));

        $factory = function (int $attempt) use ($address, $providerKey, $providerConfig, $verbose) {
            $isRetry = $attempt > 0;
            return $this->buildProviderPromise($address, $providerKey, $providerConfig, $verbose, $isRetry);
        };

        return $this->attachRetries($factory, $maxRetries, $providerKey, $providerConfig);
    }

    private function attachRetries(callable $factory, int $maxRetries, string $providerKey, array $providerConfig): PromiseInterface
    {
        $runner = function (int $attempt, int $remaining) use (&$runner, $factory, $providerKey, $providerConfig, $maxRetries) {
            $promise = $factory($attempt);

            return $promise->then(
                function ($result) use ($attempt) {
                    if ($attempt > 0 && is_array($result)) {
                        $result['meta'] = array_merge($result['meta'] ?? [], [
                            'retried' => true,
                            'retry_attempts' => $attempt,
                        ]);
                    }

                    return $result;
                },
                function ($reason) use ($attempt, $remaining, &$runner, $factory, $providerKey, $providerConfig, $maxRetries) {
                    if ($remaining <= 0 || !$this->shouldRetryProviderCall($reason)) {
                        return Create::rejectionFor($reason);
                    }

                    $message = $this->describeReason($reason);

                    Log::warning('SpeedCheck provider retry', [
                        'provider' => $providerConfig['name'] ?? $providerKey,
                        'attempt' => $attempt + 1,
                        'max_attempts' => $maxRetries + 1,
                        'remaining_retries' => $remaining - 1,
                        'error' => $message,
                    ]);

                    return $runner($attempt + 1, $remaining - 1);
                }
            );
        };

        return $runner(0, $maxRetries);
    }

    private function buildProviderPromise(\stdClass $address, string $providerKey, array $providerConfig, int $verbose, bool $isRetry): PromiseInterface
    {
        $driver = $providerConfig['driver'] ?? null;
        $httpOptions = $this->buildHttpOptionsForAttempt($isRetry, $driver);

        $providerClass = $providerConfig['class'] ?? null;

        if ($providerClass === null || !class_exists($providerClass)) {
            Log::error('Providerclass niet gevonden voor speedCheck v2', [
                'provider' => $providerKey,
                'class' => $providerClass
            ]);

            return Create::promiseFor([
                'status' => 'error',
                'message' => 'Providerclass ontbreekt',
                'data' => [],
            ]);
        }

        try {
            $providerInstance = $this->makeProviderInstance($providerClass, $driver, $providerConfig, $httpOptions);
        } catch (Throwable $throwable) {
            Log::error('Provider instantiatie mislukt', [
                'provider' => $providerKey,
                'error' => $throwable->getMessage(),
            ]);
            return Create::promiseFor([
                'status' => 'error',
                'message' => 'Provider configuratiefout',
                'data' => [],
            ]);
        }

        if (method_exists($providerInstance, 'fetchSpeedsAsync')) {
            return $this->callProviderFetchSpeedsAsync($providerInstance, $address, $providerConfig, $verbose, $providerKey);
        }

        if (method_exists($providerInstance, 'fetchSpeeds')) {
            $result = $this->callProviderFetchSpeeds($providerInstance, $address, $providerConfig, $verbose, $providerKey);

            return $result instanceof PromiseInterface ? $result : Create::promiseFor($result);
        }

        Log::error('Provider mist fetchSpeeds/fetchSpeedsAsync methode', [
            'provider' => $providerKey,
            'class' => get_class($providerInstance),
        ]);

        return Create::promiseFor([
            'status' => 'error',
            'message' => 'Provider implementatie onvolledig',
            'data' => [],
        ]);
    }

    private function buildHttpOptionsForAttempt(bool $isRetry, ?string $driver = null): array
    {
        if (!$isRetry) {
            return [];
        }

        $driverOverrides = config('speedcheck.retry.drivers', []);
        $timeout = $driver && isset($driverOverrides[$driver])
            ? (float) $driverOverrides[$driver]
            : (float) config('speedcheck.retry.timeout', 4.0);

        if ($timeout <= 0) {
            return [];
        }

        $connectTimeout = min($timeout, max(0.5, $timeout));

        return [
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
        ];
    }

    private function shouldRetryProviderCall($reason): bool
    {
        if ($reason instanceof ConnectException) {
            return true;
        }

        if ($reason instanceof RequestException) {
            $context = $reason->getHandlerContext();
            $errno = is_array($context) ? ($context['errno'] ?? null) : null;

            if ($errno !== null) {
                $timeoutErrnos = array_filter([
                    defined('CURLE_OPERATION_TIMEOUTED') ? constant('CURLE_OPERATION_TIMEOUTED') : null,
                    defined('CURLE_COULDNT_CONNECT') ? constant('CURLE_COULDNT_CONNECT') : null,
                    28,
                ], static fn ($value) => $value !== null);

                if (in_array($errno, $timeoutErrnos, true)) {
                    return true;
                }
            }

            if ($reason->getCode() === 0) {
                return true;
            }

            if (stripos($reason->getMessage(), 'timed out') !== false || stripos($reason->getMessage(), 'timeout') !== false) {
                return true;
            }
        }

        if ($reason instanceof Throwable) {
            $message = $reason->getMessage();

            if (stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false) {
                return true;
            }
        }

        return false;
    }

    private function describeReason($reason): string
    {
        if ($reason instanceof Throwable) {
            return $reason->getMessage();
        }

        if (is_scalar($reason) || (is_object($reason) && method_exists($reason, '__toString'))) {
            return (string) $reason;
        }

        return 'onbekende fout';
    }

    private function resolveSpeedcheckCacheTtl(array $providerConfig, string $status): int
    {
        if ($status === 'success' && isset($providerConfig['cache_ttl_success'])) {
            return (int) $providerConfig['cache_ttl_success'];
        }

        if ($status === 'error' && isset($providerConfig['cache_ttl_error'])) {
            return (int) $providerConfig['cache_ttl_error'];
        }

        if ($status === 'no_coverage' && isset($providerConfig['cache_ttl_no_coverage'])) {
            return (int) $providerConfig['cache_ttl_no_coverage'];
        }

        if ($status === 'error') {
            return (int) env('SPEEDCHECK_ERROR_CACHE_TTL', 300);
        }

        if ($status === 'no_coverage') {
            return (int) env('SPEEDCHECK_NO_COVERAGE_CACHE_TTL', 604800);
        }

        return (int) ($providerConfig['cache_ttl'] ?? env('SPEEDCHECK_CACHE_TTL', 43200));
    }

    private function makeProviderInstance(string $providerClass, ?string $driver, array $providerConfig, array $httpOptions)
    {
        // Minimal dispatcher: geef alleen door wat de constructor verwacht.
        switch ($driver) {
            case 'odido':
                return new $providerClass($providerConfig, $httpOptions);
            case 'kpn':
            case 'ziggo':
                return new $providerClass($httpOptions);
            default:
                return new $providerClass($providerConfig);
        }
    }

    private function buildCircuitMeta(string $providerKey): array
    {
        $isOpen = $this->circuitBreaker->isOpen($providerKey);

        return [
            'state' => $isOpen ? 'open' : 'closed',
            'retry_after_seconds' => $isOpen ? $this->circuitBreaker->remainingOpenSeconds($providerKey) : 0,
            'failures_in_window' => $this->circuitBreaker->currentFailures($providerKey),
        ];
    }

    private function callProviderFetchSpeedsAsync(
        $providerInstance,
        \stdClass $address,
        array $providerConfig,
        int $verbose,
        string $providerKey
    ): PromiseInterface {
        try {
            $reflection = new \ReflectionMethod($providerInstance, 'fetchSpeedsAsync');
            $paramCount = $reflection->getNumberOfParameters();
        } catch (\ReflectionException $exception) {
            Log::error('fetchSpeedsAsync reflectie mislukt', [
                'provider' => $providerKey,
                'error' => $exception->getMessage(),
            ]);

            return Create::promiseFor([
                'status' => 'error',
                'message' => 'Provider methode niet bruikbaar',
                'data' => [],
            ]);
        }

        try {
            if ($paramCount >= 3) {
                return $providerInstance->fetchSpeedsAsync($address, $providerConfig, $verbose);
            }

            if ($paramCount === 2) {
                return $providerInstance->fetchSpeedsAsync($address, $verbose);
            }

            return $providerInstance->fetchSpeedsAsync($address);
        } catch (Throwable $throwable) {
            Log::error('Provider fetchSpeedsAsync exception', [
                'provider' => $providerKey,
                'error' => $throwable->getMessage(),
            ]);

            return Create::promiseFor([
                'status' => 'error',
                'message' => 'Provider call mislukt',
                'data' => [],
            ]);
        }
    }

    private function callProviderFetchSpeeds(
        $providerInstance,
        \stdClass $address,
        array $providerConfig,
        int $verbose,
        string $providerKey
    ) {
        try {
            if (method_exists($providerInstance, 'fetchSpeeds')) {
                $reflection = new \ReflectionMethod($providerInstance, 'fetchSpeeds');
                $paramCount = $reflection->getNumberOfParameters();

                if ($paramCount >= 3) {
                    return $providerInstance->fetchSpeeds($address, $providerConfig, $verbose);
                }

                if ($paramCount === 2) {
                    return $providerInstance->fetchSpeeds($address, $verbose);
                }

                return $providerInstance->fetchSpeeds($address);
            }
        } catch (Throwable $throwable) {
            Log::error('Provider fetchSpeeds exception', [
                'provider' => $providerKey,
                'error' => $throwable->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Provider call mislukt',
                'data' => [],
            ];
        }

        return [
            'status' => 'error',
            'message' => 'Provider implementatie onvolledig',
            'data' => [],
        ];
    }
}
