<?php

namespace App\Libraries;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;

/**
 * Fiber Nederland (AIKA) driver voor SpeedCheck v2.
 * 
 * Fiber Nederland levert op diverse glasvezelnetwerken zoals BroFiber, ViberQ, etc.
 * 
 * API: https://api-aika.fiber.nl/api/address/check
 * 
 * Flow:
 * 1. POST naar address/check met postalCode en houseNumber
 * 2. Response bevat connection.network (netwerknaam) en delivery.status
 * 3. Filter op gewenste netwerk via config
 */
class FiberNlNetworkDirect
{
    private Client $client;
    private string $providerName;
    private string $apiUrl;
    private ?string $networkFilter;
    private int $speedMbps;

    /**
     * Shared promise cache om dubbele requests te voorkomen bij meerdere providers.
     * Key format: postcode|number|extension
     *
     * @var array<string,PromiseInterface>
     */
    private static array $promiseCache = [];
    private static array $promiseCacheOrder = [];
    private const PROMISE_CACHE_LIMIT = 200;

    public function __construct(array $config = [])
    {
        $this->providerName = (string) ($config['name'] ?? 'Fiber Nederland');
        $this->apiUrl = $config['api_url'] ?? 'https://api-aika.fiber.nl/api/address/check';
        $this->networkFilter = $config['network'] ?? null;
        $this->speedMbps = (int) ($config['speed'] ?? 1000);

        $this->client = new Client([
            'http_errors' => false,
            'timeout' => 15,
            'connect_timeout' => 6,
        ]);
    }

    public function fetchSpeedsAsync(\stdClass $address, array $providerConfig = [], int $verbose = 0): PromiseInterface
    {
        $postcode = str_replace(' ', '', $address->postcode);
        $number = (string) $address->number;
        $extension = trim((string) ($address->extension ?? ''));

        $cacheKey = sprintf('%s|%s|%s', strtoupper($postcode), $number, $extension);

        // Check cache first
        $promise = $this->getCachedPromise($cacheKey);
        if ($promise !== null) {
            if ($verbose) {
                Log::debug($this->providerName . ': FiberNL async promise hergebruikt', ['key' => $cacheKey]);
            }
        } else {
            // Create new promise
            $promise = $this->createApiPromise($postcode, $number, $extension, $verbose);
            $this->putPromiseInCache($cacheKey, $promise);
        }

        // Filter response based on network
        return $promise->then(function (array $payload) use ($verbose) {
            return $this->processResponse($payload, $verbose);
        });
    }

    public function fetchSpeeds(\stdClass $address, int $verbose = 0): array
    {
        try {
            return $this->fetchSpeedsAsync($address, [], $verbose)->wait();
        } catch (\Throwable $throwable) {
            return $this->errorResponse('FiberNL HTTP fout: ' . $throwable->getMessage());
        }
    }

    private function createApiPromise(string $postcode, string $number, string $extension, int $verbose): PromiseInterface
    {
        $body = [
            'postalCode' => $postcode,
            'houseNumber' => $number,
        ];

        if ($extension !== '') {
            $body['houseNumberExt'] = $extension;
        }

        if ($verbose) {
            Log::info($this->providerName . ": POST to {$this->apiUrl}", ['body' => $body]);
        }

        return $this->client->postAsync($this->apiUrl, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => $body,
        ])->then(
                function ($response) use ($postcode, $number, $extension, $verbose) {
                    $status = (int) $response->getStatusCode();
                    $bodyContents = (string) $response->getBody();
                    $decoded = json_decode($bodyContents, true);

                    if ($verbose) {
                        Log::info($this->providerName . ': Raw API Response', [
                            'status' => $status,
                            'connection' => $decoded['connection'] ?? null,
                            'address' => $decoded['address'] ?? null,
                        ]);
                    }

                    if ($status !== 200) {
                        Log::warning($this->providerName . ' API returned status ' . $status, [
                            'postcode' => $postcode,
                            'number' => $number,
                        ]);

                        return [
                            'ok' => false,
                            'error' => 'FiberNL status ' . $status,
                            'data' => null,
                        ];
                    }

                    if (!$decoded || !is_array($decoded)) {
                        return [
                            'ok' => false,
                            'error' => 'FiberNL ongeldige JSON response',
                            'data' => null,
                        ];
                    }

                    return [
                        'ok' => true,
                        'error' => null,
                        'data' => $decoded,
                    ];
                },
                function ($reason) use ($postcode, $number) {
                    $message = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                    Log::error($this->providerName . ' HTTP error', [
                        'postcode' => $postcode,
                        'number' => $number,
                        'error' => $message,
                    ]);

                    return [
                        'ok' => false,
                        'error' => 'FiberNL HTTP fout: ' . $message,
                        'data' => null,
                    ];
                }
            );
    }

    private function processResponse(array $payload, int $verbose): array
    {
        if (($payload['ok'] ?? false) !== true) {
            return $this->errorResponse((string) ($payload['error'] ?? 'FiberNL onbekende fout'));
        }

        $data = $payload['data'] ?? null;
        if (!$data || !is_array($data)) {
            return $this->noCoverageResponse();
        }

        // Check result status
        $result = $data['result'] ?? null;
        if ($result === 'rejected') {
            return $this->noCoverageResponse();
        }

        // Get connection info
        $connection = $data['connection'] ?? null;
        if (!$connection || !is_array($connection)) {
            return $this->noCoverageResponse();
        }

        $network = $connection['network'] ?? null;
        $delivery = $connection['delivery'] ?? [];
        $deliveryStatus = $delivery['status'] ?? null;
        $available = $delivery['available'] ?? false;

        if ($verbose) {
            Log::info($this->providerName . ': Connection info', [
                'network' => $network,
                'delivery_status' => $deliveryStatus,
                'available' => $available,
            ]);
        }

        // Filter by network if configured
        if ($this->networkFilter !== null && $network !== $this->networkFilter) {
            if ($verbose) {
                Log::info($this->providerName . ": Network mismatch, expected '{$this->networkFilter}', got '{$network}'");
            }
            return $this->noCoverageResponse();
        }

        // Check if delivery is available (status 99 = active, but available=false means not orderable)
        // We consider it "has coverage" if the network matches, regardless of order availability
        if ($network === null) {
            return $this->noCoverageResponse();
        }

        // Determine speed from area config if available
        $speed = $this->speedMbps;
        $area = $data['area'] ?? null;
        if ($area && isset($area['products']['internet']['subItems']['internet_speed']['subItems'])) {
            $speeds = $area['products']['internet']['subItems']['internet_speed']['subItems'];
            // Look for highest speed option
            foreach (['10_gbps', '5_gbps', '2_5_gbps', '1_gbps', '500_mbps', '200_mbps', '100_mbps'] as $speedKey) {
                if (isset($speeds[$speedKey])) {
                    $speed = $this->parseSpeedKey($speedKey);
                    break;
                }
            }
        }

        return [
            'status' => 'success',
            'data' => [
                [
                    'provider' => $this->providerName,
                    'download' => [
                        'dsl' => null,
                        'glasvezel' => $speed,
                        'kabel' => null,
                    ],
                ]
            ],
            'meta' => [
                'fibernl' => [
                    'network' => $network,
                    'operator' => $connection['operator'] ?? null,
                    'delivery_status' => $deliveryStatus,
                    'delivery_available' => $available,
                ],
            ],
        ];
    }

    private function parseSpeedKey(string $key): int
    {
        return match ($key) {
            '10_gbps' => 10000,
            '5_gbps' => 5000,
            '2_5_gbps' => 2500,
            '1_gbps' => 1000,
            '500_mbps' => 500,
            '200_mbps' => 200,
            '100_mbps' => 100,
            default => 1000,
        };
    }

    private function getCachedPromise(string $key): ?PromiseInterface
    {
        $promise = self::$promiseCache[$key] ?? null;
        return $promise instanceof PromiseInterface ? $promise : null;
    }

    private function putPromiseInCache(string $key, PromiseInterface $promise): void
    {
        if (isset(self::$promiseCache[$key])) {
            return;
        }

        self::$promiseCache[$key] = $promise;
        self::$promiseCacheOrder[] = $key;

        if (count(self::$promiseCacheOrder) <= self::PROMISE_CACHE_LIMIT) {
            return;
        }

        $oldestKey = array_shift(self::$promiseCacheOrder);
        if ($oldestKey !== null) {
            unset(self::$promiseCache[$oldestKey]);
        }
    }

    private function noCoverageResponse(): array
    {
        return [
            'status' => 'no_coverage',
            'data' => [
                [
                    'provider' => $this->providerName,
                    'download' => [
                        'dsl' => null,
                        'glasvezel' => null,
                        'kabel' => null,
                    ],
                ]
            ],
        ];
    }

    private function errorResponse(string $message): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'data' => [],
        ];
    }
}
